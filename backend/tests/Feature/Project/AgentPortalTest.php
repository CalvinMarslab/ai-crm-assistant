<?php

namespace Tests\Feature\Project;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Enums\HandoverItemStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CRM_WORKFLOW.md section 7 and USER_ROLES_PERMISSION.md. */
class AgentPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_summary_reports_only_the_callers_own_performance(): void
    {
        $owner = $this->owner();
        [$agentUser, $agent] = $this->portalAgent();
        $otherAgent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        $this->opportunityFor($owner, $agent, 'Mine A');
        $this->opportunityFor($owner, $agent, 'Mine B');
        $this->opportunityFor($owner, $otherAgent, 'Theirs');

        $data = $this->actingAs($agentUser)->getJson('/api/v1/portal/summary')->assertOk()->json('data');

        $this->assertSame($agent->uuid, $data['agent']['id']);
        $this->assertSame(2, $data['performance']['introduced']);

        // Commercial totals are not part of the agent-facing performance view.
        $this->assertArrayNotHasKey('estimated_value', $data['performance']);
        $this->assertArrayNotHasKey('won_value', $data['performance']);
        $this->assertArrayNotHasKey('by_stage', $data['performance']);
    }

    public function test_portal_shows_simplified_statuses_not_internal_stages(): void
    {
        $owner = $this->owner();
        [$agentUser, $agent] = $this->portalAgent();

        $opportunity = $this->opportunityFor($owner, $agent, 'Simplified');
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('proposal_sent')->id,
        ])->assertOk();

        $row = $this->actingAs($agentUser)->getJson('/api/v1/portal/opportunities')->assertOk()->json('data.0');

        // "Proposal Sent" is internal; the agent sees "Proposal Stage".
        $this->assertSame('Proposal Stage', $row['status']);
        $this->assertArrayNotHasKey('estimated_value', $row);
        $this->assertArrayNotHasKey('owner', $row);
        $this->assertArrayNotHasKey('next_action', $row);
        $this->assertArrayNotHasKey('stage', $row);
    }

    public function test_portal_status_switches_to_project_progress_once_delivery_starts(): void
    {
        $owner = $this->owner();
        [$agentUser, $agent] = $this->portalAgent();

        $opportunity = $this->opportunityFor($owner, $agent, 'Goes to delivery');
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 50000,
        ])->assertOk();

        $this->assertSame('Won', $this->portalRow($agentUser, $opportunity)['status']);

        $projectUuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [])
            ->assertCreated()->json('data.id');

        $project = Project::whereUuid($projectUuid)->with('handoverItems')->firstOrFail();
        $this->assertSame('Project In Progress', $this->portalRow($agentUser, $opportunity)['status']);

        foreach ($project->handoverItems as $item) {
            $this->actingAs($owner)->patchJson("/api/v1/projects/{$project->uuid}/handover-items/{$item->uuid}", [
                'status' => HandoverItemStatus::Done->value,
            ])->assertOk();
        }
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project->uuid}/status", [
            'status' => ProjectStatus::Completed->value,
        ])->assertOk();

        $this->assertSame('Completed', $this->portalRow($agentUser, $opportunity)['status']);
    }

    public function test_portal_cannot_reach_another_agents_referral(): void
    {
        $owner = $this->owner();
        [$agentUser] = $this->portalAgent();
        $otherAgent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        $theirs = $this->opportunityFor($owner, $otherAgent, 'Not yours');

        $this->actingAs($agentUser)->getJson("/api/v1/portal/opportunities/{$theirs->uuid}")->assertNotFound();
    }

    public function test_portal_progress_uses_agent_facing_vocabulary_only(): void
    {
        $owner = $this->owner();
        [$agentUser, $agent] = $this->portalAgent();

        $opportunity = $this->opportunityFor($owner, $agent, 'Progressing');

        foreach (['contacted', 'qualified', 'proposal_sent'] as $code) {
            $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
                'stage_id' => $this->stage($code)->id,
            ])->assertOk();
        }

        $progress = $this->actingAs($agentUser)
            ->getJson("/api/v1/portal/opportunities/{$opportunity->uuid}")
            ->assertOk()->json('data.progress');

        $statuses = array_column($progress, 'status');

        $this->assertSame(['New', 'In Discussion', 'Proposal Stage'], $statuses);
        foreach ($statuses as $status) {
            $this->assertContains($status, [
                'New', 'In Discussion', 'Proposal Stage', 'Negotiation',
                'Won', 'Lost', 'Project In Progress', 'Completed',
            ]);
        }
    }

    public function test_portal_is_closed_to_users_without_an_agent_profile(): void
    {
        $this->actingAs($this->owner())->getJson('/api/v1/portal/summary')->assertForbidden();
        $this->actingAs($this->userWithRole(RoleCode::ProjectManager))->getJson('/api/v1/portal/summary')->assertForbidden();

        // A referral agent whose account is not linked to a profile yet.
        $unlinked = $this->userWithRole(RoleCode::ReferralAgent);
        $this->actingAs($unlinked)->getJson('/api/v1/portal/summary')->assertForbidden();
    }

    public function test_agent_sees_high_level_project_progress_but_not_delivery_detail(): void
    {
        $owner = $this->owner();
        [$agentUser, $agent] = $this->portalAgent();

        $opportunity = $this->opportunityFor($owner, $agent, 'Delivery visibility');
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 90000,
        ])->assertOk();

        $projectUuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [])
            ->assertCreated()->json('data.id');

        // Listing their referral's project is allowed...
        $listed = $this->actingAs($agentUser)->getJson('/api/v1/projects')->assertOk()->json('data');
        $this->assertCount(1, $listed);

        // ...but contract value and requirements are withheld.
        $detail = $this->actingAs($agentUser)->getJson("/api/v1/projects/{$projectUuid}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('contract_value', $detail);
        $this->assertArrayNotHasKey('requirements', $detail);
        $this->assertArrayNotHasKey('quotation_reference', $detail);
        $this->assertSame('Project In Progress', $detail['agent_facing_status']);

        // And they cannot drive delivery.
        $this->actingAs($agentUser)->postJson("/api/v1/projects/{$projectUuid}/status", [
            'status' => ProjectStatus::Completed->value,
        ])->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Agent}
     */
    private function portalAgent(): array
    {
        $user = $this->userWithRole(RoleCode::ReferralAgent);
        $agent = Agent::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
        ]);

        return [$user->fresh(['roles', 'agentProfile']), $agent];
    }

    /** Created through the API so the opening stage-history entry exists. */
    private function opportunityFor(User $owner, Agent $agent, string $title): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => $title,
            'company_id' => $company->uuid,
            'referral_agent_id' => $agent->uuid,
            'estimated_value' => 60000,
        ])->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function portalRow(User $agentUser, Opportunity $opportunity): array
    {
        return $this->actingAs($agentUser)
            ->getJson("/api/v1/portal/opportunities/{$opportunity->uuid}")
            ->assertOk()
            ->json('data.opportunity');
    }
}
