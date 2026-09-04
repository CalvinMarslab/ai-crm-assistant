<?php

namespace Tests\Feature\Project;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Organization\Models\Organization;
use App\Domain\Project\Models\Project;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project manager must actually be able to manage projects. Assigning
 * somebody without that capability produces a project nobody can work on, and
 * a notification telling them to do something they cannot do.
 */
class ProjectManagerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_manager_accepts_a_project_manager_from_this_organization(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $pm->uuid])
            ->assertOk()
            ->assertJsonPath('data.manager.id', $pm->uuid);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $pm->id, 'type' => 'project.assigned']);
    }

    public function test_assign_manager_rejects_a_referral_agent(): void
    {
        $owner = $this->owner();
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $project = $this->project($owner);
        $notificationsBefore = AppNotification::count();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $agentUser->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_id');

        $this->assertNull($project->fresh()->project_manager_user_id);
        $this->assertSame($notificationsBefore, AppNotification::count(), 'A refused assignment notified the user');
        $this->assertSame(
            0,
            $project->handoverItems()->whereNotNull('assigned_user_id')->count(),
            'A refused assignment reassigned checklist items',
        );
    }

    public function test_assign_manager_rejects_a_project_manager_from_another_organization(): void
    {
        $owner = $this->owner();
        $project = $this->project($owner);
        $foreignPm = $this->foreignProjectManager();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $foreignPm->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_id');

        $this->assertNull($project->fresh()->project_manager_user_id);
    }

    public function test_assign_manager_rejects_an_inactive_user(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager, ['status' => 'inactive']);
        $project = $this->project($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $pm->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_id');
    }

    public function test_manager_can_be_cleared(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->project($owner, $pm);

        $this->assertSame($pm->id, $project->fresh()->project_manager_user_id);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => null])
            ->assertOk();

        $this->assertNull($project->fresh()->project_manager_user_id);
    }

    public function test_convert_to_project_accepts_a_valid_project_manager(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $opportunity = $this->wonOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
                'project_manager_id' => $pm->uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('data.manager.id', $pm->uuid);
    }

    public function test_convert_to_project_rejects_a_referral_agent_as_manager(): void
    {
        $owner = $this->owner();
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $opportunity = $this->wonOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
                'project_manager_id' => $agentUser->uuid,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_manager_id');

        // Nothing may be created by a refused conversion.
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_handover_items', 0);
        $this->assertDatabaseMissing('app_notifications', ['type' => 'project.assigned']);
    }

    public function test_convert_to_project_rejects_a_manager_from_another_organization(): void
    {
        $owner = $this->owner();
        $foreignPm = $this->foreignProjectManager();
        $opportunity = $this->wonOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
                'project_manager_id' => $foreignPm->uuid,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_manager_id');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_users_can_be_filtered_to_eligible_project_managers(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);

        $listed = $this->actingAs($owner)
            ->getJson('/api/v1/users?role=project_manager&active=1')
            ->assertOk()
            ->json('data');

        $ids = array_column($listed, 'id');

        $this->assertContains($pm->uuid, $ids);
        $this->assertNotContains($agentUser->uuid, $ids);
        $this->assertNotContains($owner->uuid, $ids);
    }

    private function foreignProjectManager(): User
    {
        $mine = OrganizationContext::id();

        $foreign = OrganizationContext::withoutScope(
            fn () => Organization::factory()->create(['name' => 'Foreign Org'])
        );

        OrganizationContext::set($foreign->id);
        $user = User::factory()->create();
        $user->roles()->sync([Role::firstWhere('code', RoleCode::ProjectManager->value)->id]);
        OrganizationContext::set($mine);

        return $user;
    }

    private function wonOpportunity(User $owner): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Assignment test '.uniqid(),
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 10000,
        ])->assertOk();

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }

    private function project(User $owner, ?User $manager = null): Project
    {
        $opportunity = $this->wonOpportunity($owner);

        $uuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
                'project_manager_id' => $manager?->uuid,
            ])->assertCreated()->json('data.id');

        return Project::whereUuid($uuid)->firstOrFail();
    }
}
