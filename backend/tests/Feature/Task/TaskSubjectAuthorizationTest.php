<?php

namespace Tests\Feature\Task;

use App\Domain\Activity\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Organization\Models\Organization;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A task carries an activity onto whatever it is attached to, so attaching one
 * is a write against that record. Permission to manage tasks in general is not
 * permission to write onto any record in the organization.
 */
class TaskSubjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_can_create_a_task_on_their_own_project(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->projectFor($owner, $pm);

        $this->actingAs($pm)
            ->postJson('/api/v1/tasks', [
                'title' => 'Book the kick-off call',
                'subject_type' => 'project',
                'subject_id' => $project->uuid,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('tasks', ['title' => 'Book the kick-off call', 'subject_id' => $project->id]);
    }

    public function test_project_manager_cannot_create_a_task_on_another_managers_project(): void
    {
        $owner = $this->owner();
        $mine = $this->userWithRole(RoleCode::ProjectManager);
        $theirs = $this->userWithRole(RoleCode::ProjectManager);
        $theirProject = $this->projectFor($owner, $theirs);

        $activitiesBefore = Activity::count();
        $notificationsBefore = AppNotification::count();

        $this->actingAs($mine)
            ->postJson('/api/v1/tasks', [
                'title' => 'Meddling',
                'subject_type' => 'project',
                'subject_id' => $theirProject->uuid,
            ])
            ->assertForbidden();

        // A refused request must leave nothing behind.
        $this->assertDatabaseMissing('tasks', ['title' => 'Meddling']);
        $this->assertSame($activitiesBefore, Activity::count(), 'A refused task wrote an activity');
        $this->assertSame($notificationsBefore, AppNotification::count(), 'A refused task sent a notification');
    }

    public function test_project_manager_cannot_rebind_an_existing_task_to_an_inaccessible_project(): void
    {
        $owner = $this->owner();
        $mine = $this->userWithRole(RoleCode::ProjectManager);
        $theirs = $this->userWithRole(RoleCode::ProjectManager);

        $myProject = $this->projectFor($owner, $mine);
        $theirProject = $this->projectFor($owner, $theirs);

        $taskUuid = $this->actingAs($mine)->postJson('/api/v1/tasks', [
            'title' => 'Legitimate task',
            'subject_type' => 'project',
            'subject_id' => $myProject->uuid,
        ])->assertCreated()->json('data.id');

        $this->actingAs($mine)
            ->patchJson("/api/v1/tasks/{$taskUuid}", [
                'subject_type' => 'project',
                'subject_id' => $theirProject->uuid,
            ])
            ->assertForbidden();

        $this->assertSame($myProject->id, Task::whereUuid($taskUuid)->first()->subject_id);
    }

    public function test_project_manager_cannot_attach_tasks_to_sales_records(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $opportunity = $this->opportunityFor($owner);

        foreach ([
            ['opportunity', $opportunity->uuid],
            ['company', $company->uuid],
        ] as [$type, $uuid]) {
            $this->actingAs($pm)
                ->postJson('/api/v1/tasks', ['title' => 'Out of scope', 'subject_type' => $type, 'subject_id' => $uuid])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('tasks', ['title' => 'Out of scope']);
    }

    public function test_owner_can_create_a_task_on_any_project_in_the_organization(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->projectFor($owner, $pm);

        $this->actingAs($owner)
            ->postJson('/api/v1/tasks', [
                'title' => 'Owner oversight task',
                'subject_type' => 'project',
                'subject_id' => $project->uuid,
            ])
            ->assertCreated();
    }

    public function test_a_subject_from_another_organization_is_not_reachable(): void
    {
        $owner = $this->owner();
        $mine = OrganizationContext::id();

        $foreign = OrganizationContext::withoutScope(
            fn () => Organization::factory()->create(['name' => 'Foreign'])
        );
        OrganizationContext::set($foreign->id);
        $foreignCompany = Company::factory()->create();
        OrganizationContext::set($mine);

        $response = $this->actingAs($owner)->postJson('/api/v1/tasks', [
            'title' => 'Cross tenant',
            'subject_type' => 'company',
            'subject_id' => $foreignCompany->uuid,
        ]);

        $this->assertContains($response->status(), [403, 404, 422]);
        $this->assertDatabaseMissing('tasks', ['title' => 'Cross tenant']);
    }

    public function test_an_unknown_subject_is_rejected_rather_than_silently_saved(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner)->postJson('/api/v1/tasks', [
            'title' => 'Dangling subject',
            'subject_type' => 'project',
            'subject_id' => '00000000-0000-4000-8000-000000000000',
        ]);

        $this->assertContains($response->status(), [404, 422], "Got {$response->status()}");

        // The old behaviour saved the task with a null subject.
        $this->assertDatabaseMissing('tasks', ['title' => 'Dangling subject']);
    }

    private function opportunityFor(User $owner): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Sales record '.uniqid(),
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }

    private function projectFor(User $owner, User $manager): Project
    {
        $opportunity = $this->opportunityFor($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 10000,
        ])->assertOk();

        $uuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
                'project_manager_id' => $manager->uuid,
            ])->assertCreated()->json('data.id');

        return Project::whereUuid($uuid)->firstOrFail();
    }
}
