<?php

namespace Tests\Feature\Task;

use App\Domain\Activity\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Enums\TaskStatus;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Being the creator or assignee of a task is not a standing right to it. When
 * the record a task hangs off moves out of reach — a project reassigned to
 * another manager — the task goes with it.
 */
class TaskSubjectAccessRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_can_create_a_task_on_their_own_project(): void
    {
        [$owner, $formerPm, $project] = $this->projectWithManager();

        $this->actingAs($formerPm)
            ->postJson('/api/v1/tasks', [
                'title' => 'Kick-off call',
                'subject_type' => 'project',
                'subject_id' => $project->uuid,
            ])
            ->assertCreated();
    }

    public function test_a_former_manager_loses_every_operation_on_the_projects_tasks(): void
    {
        [$owner, $formerPm, $project, $taskUuid] = $this->projectWithTaskThenReassigned();

        $activitiesBefore = Activity::count();
        $notificationsBefore = AppNotification::count();
        $taskBefore = Task::whereUuid($taskUuid)->firstOrFail()->toArray();

        $this->actingAs($formerPm)->getJson("/api/v1/tasks/{$taskUuid}")->assertForbidden();
        $this->actingAs($formerPm)->patchJson("/api/v1/tasks/{$taskUuid}", ['title' => 'Hijacked'])->assertForbidden();
        $this->actingAs($formerPm)->postJson("/api/v1/tasks/{$taskUuid}/complete")->assertForbidden();
        $this->actingAs($formerPm)->postJson("/api/v1/tasks/{$taskUuid}/reopen")->assertForbidden();
        $this->actingAs($formerPm)->deleteJson("/api/v1/tasks/{$taskUuid}")->assertForbidden();

        // Nothing may change behind a refusal.
        $taskAfter = Task::whereUuid($taskUuid)->firstOrFail()->toArray();
        $this->assertSame($taskBefore, $taskAfter, 'A refused request changed the task');
        $this->assertSame($activitiesBefore, Activity::count(), 'A refused request wrote an activity');
        $this->assertSame($notificationsBefore, AppNotification::count(), 'A refused request sent a notification');
    }

    public function test_a_former_managers_task_list_no_longer_includes_the_task(): void
    {
        [, $formerPm, , $taskUuid] = $this->projectWithTaskThenReassigned();

        $listed = $this->actingAs($formerPm)->getJson('/api/v1/tasks')->assertOk()->json('data');

        $this->assertNotContains($taskUuid, array_column($listed, 'id'));
    }

    public function test_the_new_manager_can_see_and_work_the_task(): void
    {
        [, , $project, $taskUuid, $newPm] = $this->projectWithTaskThenReassigned();

        $this->actingAs($newPm)->getJson("/api/v1/tasks/{$taskUuid}")->assertOk();
        $this->actingAs($newPm)->postJson("/api/v1/tasks/{$taskUuid}/complete")->assertOk();

        $this->assertSame(TaskStatus::Done, Task::whereUuid($taskUuid)->first()->status);
        $this->assertNotNull($project);
    }

    public function test_the_owner_keeps_access_throughout(): void
    {
        [$owner, , , $taskUuid] = $this->projectWithTaskThenReassigned();

        $this->actingAs($owner)->getJson("/api/v1/tasks/{$taskUuid}")->assertOk();
        $this->actingAs($owner)->patchJson("/api/v1/tasks/{$taskUuid}", ['title' => 'Owner edit'])->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/tasks/{$taskUuid}/complete")->assertOk();
    }

    public function test_a_personal_task_with_no_subject_keeps_its_existing_behaviour(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $uuid = $this->actingAs($pm)
            ->postJson('/api/v1/tasks', ['title' => 'Personal reminder'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($pm)->getJson("/api/v1/tasks/{$uuid}")->assertOk();
        $this->actingAs($pm)->postJson("/api/v1/tasks/{$uuid}/complete")->assertOk();
        $this->actingAs($pm)->postJson("/api/v1/tasks/{$uuid}/reopen")->assertOk();

        $listed = $this->actingAs($pm)->getJson('/api/v1/tasks')->json('data');
        $this->assertContains($uuid, array_column($listed, 'id'));

        $this->assertNotNull($owner);
    }

    public function test_the_same_rule_applies_to_sales_records(): void
    {
        $owner = $this->owner();
        $salesperson = $this->owner();

        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($salesperson)->postJson('/api/v1/opportunities', [
            'title' => 'Reassigned deal',
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        $opportunity = Opportunity::whereUuid($uuid)->firstOrFail();

        $taskUuid = $this->actingAs($salesperson)->postJson('/api/v1/tasks', [
            'title' => 'Chase the customer',
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
        ])->assertCreated()->json('data.id');

        // A salesperson who can see every opportunity keeps access; the point
        // here is that the subject rule is applied rather than skipped.
        $this->actingAs($salesperson)->getJson("/api/v1/tasks/{$taskUuid}")->assertOk();

        // A project manager, who cannot view this opportunity, must not reach
        // its task even if it is assigned to them.
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        Task::whereUuid($taskUuid)->first()->update(['assigned_user_id' => $pm->id]);

        $this->actingAs($pm)->getJson("/api/v1/tasks/{$taskUuid}")->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2: Project}
     */
    private function projectWithManager(): array
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $opportunityUuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Delivery '.uniqid(),
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunityUuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 20000,
        ])->assertOk();

        $projectUuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunityUuid}/convert-to-project", [
                'project_manager_id' => $pm->uuid,
            ])->assertCreated()->json('data.id');

        return [$owner, $pm, Project::whereUuid($projectUuid)->firstOrFail()];
    }

    /**
     * @return array{0: User, 1: User, 2: Project, 3: string, 4: User}
     */
    private function projectWithTaskThenReassigned(): array
    {
        [$owner, $formerPm, $project] = $this->projectWithManager();

        $taskUuid = $this->actingAs($formerPm)->postJson('/api/v1/tasks', [
            'title' => 'Handover work',
            'subject_type' => 'project',
            'subject_id' => $project->uuid,
        ])->assertCreated()->json('data.id');

        $newPm = $this->userWithRole(RoleCode::ProjectManager);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $newPm->uuid])
            ->assertOk();

        return [$owner, $formerPm, $project, $taskUuid, $newPm];
    }
}
