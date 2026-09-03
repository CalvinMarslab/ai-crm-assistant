<?php

namespace Tests\Feature\Permission;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_see_other_agents_opportunities(): void
    {
        $owner = $this->owner();

        [$agentUser, $agent] = $this->referralAgent();
        $otherAgent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        $mine = $this->makeOpportunity($owner, ['referral_agent_id' => $agent->id, 'title' => 'My Referral']);
        $theirs = $this->makeOpportunity($owner, ['referral_agent_id' => $otherAgent->id, 'title' => 'Their Referral']);

        $response = $this->actingAs($agentUser)->getJson('/api/v1/opportunities');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($mine->uuid, $response->json('data.0.id'));

        // And not reachable by direct address either.
        $this->actingAs($agentUser)->getJson("/api/v1/opportunities/{$theirs->uuid}")->assertForbidden();
        $this->actingAs($agentUser)->getJson("/api/v1/opportunities/{$mine->uuid}")->assertOk();
    }

    public function test_agent_cannot_see_internal_notes(): void
    {
        $owner = $this->owner();
        [$agentUser, $agent] = $this->referralAgent();

        $opportunity = $this->makeOpportunity($owner, ['referral_agent_id' => $agent->id]);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/notes", [
            'body' => 'Our margin on this is thin.',
            'is_internal' => true,
        ])->assertCreated();

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/notes", [
            'body' => 'Customer confirmed the requirements.',
            'is_internal' => false,
        ])->assertCreated();

        $ownerTimeline = $this->actingAs($owner)
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")->json('data');
        $agentTimeline = $this->actingAs($agentUser)
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")->json('data');

        $ownerBodies = array_column($ownerTimeline, 'body');
        $agentBodies = array_column($agentTimeline, 'body');

        $this->assertContains('Our margin on this is thin.', $ownerBodies);
        $this->assertNotContains('Our margin on this is thin.', $agentBodies);
        $this->assertContains('Customer confirmed the requirements.', $agentBodies);
    }

    public function test_agent_cannot_see_confidential_financials(): void
    {
        $owner = $this->owner();
        [$agentUser, $agent] = $this->referralAgent();

        $opportunity = $this->makeOpportunity($owner, [
            'referral_agent_id' => $agent->id,
            'estimated_value' => 90000,
            'quotation_amount' => 88000,
        ]);

        $ownerView = $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}")->json('data');
        $agentView = $this->actingAs($agentUser)->getJson("/api/v1/opportunities/{$opportunity->uuid}")->json('data');

        $this->assertEqualsWithDelta(90000, $ownerView['estimated_value'], 0.001);
        $this->assertArrayNotHasKey('estimated_value', $agentView);
        $this->assertArrayNotHasKey('quotation_amount', $agentView);

        // The agent still gets the simplified status they are entitled to.
        $this->assertSame('New', $agentView['stage']['agent_facing_status']);
    }

    public function test_agent_cannot_view_internal_tasks(): void
    {
        $owner = $this->owner();
        [$agentUser] = $this->referralAgent();

        Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Internal prep work',
        ]);

        // The referral agent role carries no task permission at all.
        $this->actingAs($agentUser)->getJson('/api/v1/tasks')->assertForbidden();
    }

    public function test_agent_cannot_view_audit_logs(): void
    {
        [$agentUser] = $this->referralAgent();

        $this->actingAs($agentUser)->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_project_manager_cannot_access_unrelated_opportunities_by_default(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $unrelated = $this->makeOpportunity($owner, ['title' => 'Unrelated deal']);
        $theirs = $this->makeOpportunity($pm, ['title' => 'Assigned to the PM']);

        $response = $this->actingAs($pm)->getJson('/api/v1/opportunities');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($theirs->uuid, $response->json('data.0.id'));

        $this->actingAs($pm)->getJson("/api/v1/opportunities/{$unrelated->uuid}")->assertForbidden();
    }

    public function test_project_manager_cannot_change_agent_ownership(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $opportunity = $this->makeOpportunity($pm);
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($pm)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/agent", ['agent_id' => $agent->uuid])
            ->assertForbidden();
    }

    public function test_project_manager_cannot_manage_users(): void
    {
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $this->actingAs($pm)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_owner_can_view_all_records(): void
    {
        $owner = $this->owner();
        $other = $this->owner();

        $this->makeOpportunity($other, ['title' => 'Someone else\'s deal']);
        $this->makeOpportunity($owner, ['title' => 'My deal']);

        $this->actingAs($owner)->getJson('/api/v1/opportunities')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($owner)->getJson('/api/v1/companies')->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/audit-logs')->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/users')->assertOk();
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOpportunity(User $owner, array $attributes = []): Opportunity
    {
        return Opportunity::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $this->pipeline()->id,
            'stage_id' => $this->stage('new_lead')->id,
        ], $attributes));
    }
}
