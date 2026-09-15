<?php

namespace Tests\Feature\Task;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskVisibility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * is_internal marks a task as staff-only. USER_ROLES_PERMISSION.md says a
 * referral agent cannot view internal tasks; this is the boundary that makes
 * that true rather than merely stated.
 *
 * The flag previously existed and filtered nothing, which is worse than not
 * having it — a column that looks like a control but is not one.
 */
class InternalTaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_permission_is_held_by_staff_and_not_by_referral_agents(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        [$agentUser] = $this->referralAgent();

        $this->assertTrue($owner->canDo(PermissionCode::TaskViewInternal));
        $this->assertTrue($pm->canDo(PermissionCode::TaskViewInternal));
        $this->assertFalse($agentUser->canDo(PermissionCode::TaskViewInternal));
    }

    public function test_an_internal_task_is_withheld_from_someone_without_the_permission(): void
    {
        $owner = $this->owner();
        [$agentUser] = $this->referralAgent();

        $internal = $this->task($owner, 'Internal margin review', true);
        $shared = $this->task($owner, 'Shareable update', false);

        $visibility = app(TaskVisibility::class);

        $this->assertTrue($visibility->decide($owner, $internal));
        $this->assertTrue($visibility->decide($owner, $shared));

        // The decisive case: even assigned to them, an internal task stays shut.
        $internal->update(['assigned_user_id' => $agentUser->id]);
        $shared->update(['assigned_user_id' => $agentUser->id]);

        $this->assertFalse(
            $visibility->decide($agentUser->fresh(['roles', 'agentProfile']), $internal->fresh()),
            'An internal task reached someone without task.view.internal',
        );
    }

    public function test_the_listing_query_and_the_policy_agree(): void
    {
        $owner = $this->owner();
        [$agentUser] = $this->referralAgent();

        $internal = $this->task($owner, 'Internal only', true);
        $internal->update(['assigned_user_id' => $agentUser->id]);

        $visibility = app(TaskVisibility::class);
        $agentUser = $agentUser->fresh(['roles', 'agentProfile']);

        $listed = $visibility->scope(Task::query(), $agentUser)->pluck('id')->all();

        // Whatever the list returns, the per-record rule must agree with it.
        $this->assertNotContains($internal->id, $listed);

        foreach (Task::all() as $task) {
            $this->assertSame(
                in_array($task->id, $listed, true),
                $visibility->decide($agentUser, $task),
                "List and policy disagree on task {$task->id}",
            );
        }
    }

    public function test_a_project_manager_still_sees_the_internal_tasks_on_their_own_work(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        // Deliberately subject-less, to isolate the internal boundary from the
        // separate rule that a task follows access to the record it hangs off.
        $task = Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $pm->id,
            'title' => 'Delivery detail',
            'is_internal' => true,
        ]);

        // Tasks default to internal, so a boundary drawn in the wrong place
        // would leave delivery staff unable to see their own work.
        $listed = $this->actingAs($pm)->getJson('/api/v1/tasks')->assertOk()->json('data');

        $this->assertContains($task->uuid, array_column($listed, 'id'));
    }

    public function test_the_dashboard_applies_the_same_boundary(): void
    {
        $owner = $this->owner();

        $internal = $this->task($owner, 'Internal overdue', true);
        $internal->update(['due_at' => now()->subDays(2), 'assigned_user_id' => $owner->id]);

        $titles = array_column(
            $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data.sections.overdue_tasks'),
            'title',
        );

        $this->assertContains('Internal overdue', $titles, 'Staff lost sight of their own overdue work');
    }

    public function test_the_assistant_inherits_the_boundary(): void
    {
        $owner = $this->owner();
        $this->task($owner, 'Internal via assistant', true);
        $this->task($owner, 'Shared via assistant', false);

        $tool = app(\App\Domain\Ai\Tools\GetTasksTool::class);

        // The AI tool scopes through the same service, so it cannot become a
        // side door around the boundary.
        $ownerTitles = array_column($tool->execute($owner, ['bucket' => 'all'])['tasks'], 'title');
        $this->assertContains('Internal via assistant', $ownerTitles);

        [$agentUser] = $this->referralAgent();
        $agentTitles = array_column(
            $tool->execute($agentUser->fresh(['roles', 'agentProfile']), ['bucket' => 'all'])['tasks'],
            'title',
        );

        $this->assertNotContains('Internal via assistant', $agentTitles);
    }

    /**
     * The configuration the filter actually exists for.
     *
     * A referral agent has no task permission at all, so they are stopped long
     * before is_internal is consulted — which means a test using them proves
     * nothing about this code. USER_ROLES_PERMISSION.md calls for granular
     * codes precisely so roles like this one can exist, and this is where the
     * boundary earns its keep.
     */
    public function test_a_role_that_can_see_tasks_but_not_internal_ones_is_held_to_the_boundary(): void
    {
        $owner = $this->owner();
        $coordinator = $this->coordinator();

        $internal = Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $coordinator->id,
            'title' => 'Internal only',
            'is_internal' => true,
        ]);

        $shared = Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $coordinator->id,
            'title' => 'Shared with the coordinator',
            'is_internal' => false,
        ]);

        $visibility = app(TaskVisibility::class);

        $this->assertFalse($visibility->decide($coordinator, $internal), 'An internal task was readable');
        $this->assertTrue($visibility->decide($coordinator, $shared));

        $listed = array_column(
            $this->actingAs($coordinator)->getJson('/api/v1/tasks')->assertOk()->json('data'),
            'id',
        );

        $this->assertNotContains($internal->uuid, $listed);
        $this->assertContains($shared->uuid, $listed);

        // And not reachable by direct address either.
        $this->actingAs($coordinator)->getJson("/api/v1/tasks/{$internal->uuid}")->assertForbidden();
        $this->actingAs($coordinator)->postJson("/api/v1/tasks/{$internal->uuid}/complete")->assertForbidden();
    }

    public function test_the_dashboard_holds_that_role_to_the_same_boundary(): void
    {
        $owner = $this->owner();
        $coordinator = $this->coordinator();

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $coordinator->id,
            'title' => 'Internal overdue',
            'due_at' => now()->subDays(2),
            'is_internal' => true,
        ]);

        $titles = array_column(
            $this->actingAs($coordinator)->getJson('/api/v1/dashboard')->json('data.sections.overdue_tasks'),
            'title',
        );

        $this->assertNotContains('Internal overdue', $titles);
    }

    /**
     * A role that handles tasks but is not trusted with internal ones — the
     * shape the granular permission design is meant to allow.
     */
    private function coordinator(): User
    {
        $role = \App\Domain\Identity\Models\Role::create([
            'organization_id' => $this->organization->id,
            'code' => 'coordinator',
            'name' => 'Coordinator',
        ]);

        $role->permissions()->sync(
            \App\Domain\Identity\Models\Permission::whereIn('code', [
                PermissionCode::TaskViewOwn->value,
                PermissionCode::TaskManage->value,
                PermissionCode::DashboardViewOwner->value,
            ])->pluck('id'),
        );

        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        $user->roles()->sync([$role->id]);

        return $user->fresh('roles');
    }

    /**
     * @return array{0: User, 1: Agent}
     */
    private function referralAgent(): array
    {
        $user = $this->userWithRole(RoleCode::ReferralAgent);
        $agent = Agent::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
        ]);

        return [$user->fresh(['roles', 'agentProfile']), $agent];
    }

    private function task(User $creator, string $title, bool $internal): Task
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($creator)->postJson('/api/v1/opportunities', [
            'title' => 'Holder '.uniqid(),
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        return Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $creator->id,
            'assigned_user_id' => $creator->id,
            'subject_type' => 'opportunity',
            'subject_id' => Opportunity::whereUuid($uuid)->value('id'),
            'title' => $title,
            'is_internal' => $internal,
        ]);
    }
}
