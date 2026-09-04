<?php

namespace Tests\Feature\Project;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * USER_ROLES_PERMISSION.md: a referral agent may see high-level progress for
 * their own referrals, but never internal tasks or delivery detail. Having
 * view access to the project record must not open its sub-resources.
 */
class ProjectInternalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_agent_cannot_read_project_tasks(): void
    {
        [$owner, $agentUser, $project] = $this->referredProject();

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'title' => 'Internal margin review',
            'description' => 'Check delivery cost against the quoted price.',
            'is_internal' => true,
        ]);

        $response = $this->actingAs($agentUser)->getJson("/api/v1/projects/{$project->uuid}/tasks");

        $response->assertForbidden();
        $this->assertStringNotContainsString('Internal margin review', $response->getContent());
    }

    public function test_referral_agent_cannot_read_project_internals_through_any_endpoint(): void
    {
        [$owner, $agentUser, $project] = $this->referredProject();

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'title' => 'Internal margin review',
            'description' => 'Check delivery cost against the quoted price.',
            'is_internal' => true,
        ]);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project->uuid}/notes", [
            'body' => 'Confidential: subcontractor rate is RM 400/day.',
            'is_internal' => true,
        ])->assertCreated();

        foreach ([
            "/api/v1/projects/{$project->uuid}/tasks",
            "/api/v1/projects/{$project->uuid}/timeline",
            "/api/v1/projects/{$project->uuid}/handover-items",
            "/api/v1/projects/{$project->uuid}/handover-brief",
        ] as $url) {
            $response = $this->actingAs($agentUser)->getJson($url);

            $this->assertSame(403, $response->status(), "{$url} returned {$response->status()} for a referral agent");

            $body = $response->getContent();
            foreach (['Internal margin review', 'subcontractor', 'Confirm company and contact details'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$url} leaked internal content");
            }
        }
    }

    public function test_referral_agent_still_sees_high_level_project_progress(): void
    {
        [, $agentUser, $project] = $this->referredProject();

        // The record itself stays visible: that is the "high-level progress"
        // the specification allows.
        $data = $this->actingAs($agentUser)->getJson("/api/v1/projects/{$project->uuid}")->assertOk()->json('data');

        $this->assertSame('Project In Progress', $data['agent_facing_status']);
        $this->assertArrayNotHasKey('contract_value', $data);
        $this->assertArrayNotHasKey('requirements', $data);
    }

    public function test_project_manager_can_only_read_tasks_of_their_own_projects(): void
    {
        $owner = $this->owner();
        $mine = $this->userWithRole(RoleCode::ProjectManager);
        $theirs = $this->userWithRole(RoleCode::ProjectManager);

        $myProject = $this->projectFor($owner, $mine);
        $theirProject = $this->projectFor($owner, $theirs);

        $this->actingAs($mine)->getJson("/api/v1/projects/{$myProject->uuid}/tasks")->assertOk();
        $this->actingAs($mine)->getJson("/api/v1/projects/{$theirProject->uuid}/tasks")->assertForbidden();
    }

    public function test_owner_can_read_all_project_tasks(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->projectFor($owner, $pm);

        $this->actingAs($owner)->getJson("/api/v1/projects/{$project->uuid}/tasks")->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project->uuid}/timeline")->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project->uuid}/handover-items")->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project->uuid}/handover-brief")->assertOk();
    }

    /**
     * @return array{0: User, 1: User, 2: Project}
     */
    private function referredProject(): array
    {
        $owner = $this->owner();
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        $agent = Agent::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $agentUser->id,
        ]);

        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Referred delivery',
            'company_id' => $company->uuid,
            'referral_agent_id' => $agent->uuid,
        ])->assertCreated()->json('data.id');

        $opportunity = Opportunity::whereUuid($uuid)->firstOrFail();

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 50000,
        ])->assertOk();

        $projectUuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [])
            ->assertCreated()->json('data.id');

        return [$owner, $agentUser->fresh(['roles', 'agentProfile']), Project::whereUuid($projectUuid)->firstOrFail()];
    }

    private function projectFor(User $owner, User $manager): Project
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Delivery '.uniqid(),
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 10000,
        ])->assertOk();

        $projectUuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$uuid}/convert-to-project", ['project_manager_id' => $manager->uuid])
            ->assertCreated()->json('data.id');

        return Project::whereUuid($projectUuid)->firstOrFail();
    }
}
