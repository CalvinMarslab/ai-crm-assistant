<?php

namespace Tests\Feature\Project;

use App\Domain\Activity\Models\Activity;
use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Enums\HandoverItemStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectHandoverItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The handover checklist is the incoming manager's work list. When a project
 * changes hands the outstanding items must change hands with it, while
 * completed items keep the person who actually did them.
 */
class HandoverReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_a_first_manager_hands_them_the_unassigned_items(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner);

        $this->assertSame(0, $project->handoverItems()->whereNotNull('assigned_user_id')->count());

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $pm->uuid])
            ->assertOk();

        $this->assertSame(
            $project->handoverItems()->count(),
            $project->handoverItems()->where('assigned_user_id', $pm->id)->count(),
        );
    }

    public function test_outstanding_items_follow_the_project_to_the_new_manager(): void
    {
        $owner = $this->owner();
        $formerPm = $this->userWithRole(RoleCode::ProjectManager);
        $newPm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner, $formerPm);

        $outstanding = $project->handoverItems()->orderBy('sequence')->get();
        $this->assertTrue($outstanding->every(fn ($i) => $i->assigned_user_id === $formerPm->id));

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $newPm->uuid])
            ->assertOk();

        $this->assertSame(
            0,
            $project->handoverItems()->where('assigned_user_id', $formerPm->id)->count(),
            'Outstanding items were left with the former manager',
        );
        $this->assertSame(
            $outstanding->count(),
            $project->handoverItems()->where('assigned_user_id', $newPm->id)->count(),
        );
    }

    public function test_items_delegated_to_a_third_person_are_left_alone(): void
    {
        $owner = $this->owner();
        $formerPm = $this->userWithRole(RoleCode::ProjectManager);
        $newPm = $this->userWithRole(RoleCode::ProjectManager);
        $helper = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner, $formerPm);

        $delegated = $project->handoverItems()->orderBy('sequence')->first();
        $delegated->update(['assigned_user_id' => $helper->id]);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $newPm->uuid])
            ->assertOk();

        $this->assertSame(
            $helper->id,
            $delegated->fresh()->assigned_user_id,
            'A deliberate delegation was overwritten by the reassignment',
        );
    }

    public function test_settled_items_keep_whoever_actually_did_them(): void
    {
        $owner = $this->owner();
        $formerPm = $this->userWithRole(RoleCode::ProjectManager);
        $newPm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner, $formerPm);

        $items = $project->handoverItems()->orderBy('sequence')->get();
        $done = $items[0];
        $notApplicable = $items[1];

        $done->update(['status' => HandoverItemStatus::Done, 'completed_at' => now()]);
        $notApplicable->update(['status' => HandoverItemStatus::NotApplicable, 'completed_at' => now()]);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $newPm->uuid])
            ->assertOk();

        $this->assertSame($formerPm->id, $done->fresh()->assigned_user_id, 'A completed item lost its history');
        $this->assertSame($formerPm->id, $notApplicable->fresh()->assigned_user_id, 'A settled item lost its history');
    }

    public function test_clearing_the_manager_leaves_outstanding_items_unassigned(): void
    {
        $owner = $this->owner();
        $formerPm = $this->userWithRole(RoleCode::ProjectManager);
        $helper = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner, $formerPm);

        $delegated = $project->handoverItems()->orderBy('sequence')->first();
        $delegated->update(['assigned_user_id' => $helper->id]);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => null])
            ->assertOk();

        $this->assertSame(
            0,
            $project->handoverItems()->where('assigned_user_id', $formerPm->id)->count(),
            'Outstanding items stayed with a manager who no longer holds the project',
        );
        $this->assertSame($helper->id, $delegated->fresh()->assigned_user_id, 'A delegation was cleared');
    }

    public function test_a_refused_assignment_changes_neither_project_nor_checklist(): void
    {
        $owner = $this->owner();
        $formerPm = $this->userWithRole(RoleCode::ProjectManager);
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $project = $this->project($owner, $formerPm);

        $itemsBefore = $project->handoverItems()->orderBy('sequence')->get()
            ->map(fn (ProjectHandoverItem $i) => [$i->id, $i->assigned_user_id, $i->status->value])->all();
        $activitiesBefore = Activity::count();
        $notificationsBefore = AppNotification::count();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $agentUser->uuid])
            ->assertStatus(422);

        $itemsAfter = $project->handoverItems()->orderBy('sequence')->get()
            ->map(fn (ProjectHandoverItem $i) => [$i->id, $i->assigned_user_id, $i->status->value])->all();

        $this->assertSame($formerPm->id, $project->fresh()->project_manager_user_id);
        $this->assertSame($itemsBefore, $itemsAfter, 'A refused assignment altered the checklist');
        $this->assertSame($activitiesBefore, Activity::count());
        $this->assertSame($notificationsBefore, AppNotification::count());
    }

    public function test_a_successful_reassignment_records_exactly_one_activity_and_one_notification(): void
    {
        $owner = $this->owner();
        $formerPm = $this->userWithRole(RoleCode::ProjectManager);
        $newPm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner, $formerPm);

        $activitiesBefore = Activity::where('activity_type', 'project.manager_assigned')->count();
        $notificationsBefore = AppNotification::where('user_id', $newPm->id)->count();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $newPm->uuid])
            ->assertOk();

        $this->assertSame(
            $activitiesBefore + 1,
            Activity::where('activity_type', 'project.manager_assigned')->count(),
        );
        $this->assertSame(
            $notificationsBefore + 1,
            AppNotification::where('user_id', $newPm->id)->where('type', 'project.assigned')->count(),
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.updated']);
    }

    private function project(User $owner, ?User $manager = null): Project
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Handover '.uniqid(),
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 15000,
        ])->assertOk();

        $projectUuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$uuid}/convert-to-project", [
                'project_manager_id' => $manager?->uuid,
            ])->assertCreated()->json('data.id');

        return Project::whereUuid($projectUuid)->firstOrFail();
    }
}
