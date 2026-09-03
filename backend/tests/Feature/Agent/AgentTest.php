<?php

namespace Tests\Feature\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_agent(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/v1/agents', [
                'name' => 'Brian Tan',
                'company_name' => 'BT Consulting',
                'email' => 'brian@bt.test',
                'joined_at' => '2025-03-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Brian Tan');

        $this->assertDatabaseHas('agents', ['name' => 'Brian Tan']);
    }

    public function test_opportunity_can_be_linked_to_agent(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Brian Tan']);

        $this->actingAs($owner)
            ->postJson('/api/v1/opportunities', [
                'title' => 'Referred project',
                'company_id' => $company->uuid,
                'referral_agent_id' => $agent->uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('data.referral_agent.name', 'Brian Tan');
    }

    public function test_agent_statistics_are_calculated_from_linked_opportunities(): void
    {
        $owner = $this->owner();
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        // Two open, one won, one lost.
        $this->makeOpportunity($owner, $agent, ['estimated_value' => 40000]);
        $this->makeOpportunity($owner, $agent, ['estimated_value' => 20000]);

        $won = $this->makeOpportunity($owner, $agent, ['estimated_value' => 100000]);
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$won->uuid}/stage", [
            'stage_id' => $this->stage('won')->id,
            'final_value' => 110000,
        ])->assertOk();

        $lost = $this->makeOpportunity($owner, $agent, ['estimated_value' => 15000]);
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$lost->uuid}/stage", [
            'stage_id' => $this->stage('lost')->id,
            'loss_reason' => 'Price',
        ])->assertOk();

        $stats = $this->actingAs($owner)->getJson("/api/v1/agents/{$agent->uuid}/stats")->json('data');

        $this->assertSame(4, $stats['introduced']);
        $this->assertSame(2, $stats['active']);
        $this->assertSame(1, $stats['won']);
        $this->assertSame(1, $stats['lost']);
        $this->assertEqualsWithDelta(60000, $stats['estimated_value'], 0.01);
        $this->assertEqualsWithDelta(110000, $stats['won_value'], 0.01);
        $this->assertEqualsWithDelta(50.0, $stats['conversion_rate'], 0.01);
        $this->assertNotEmpty($stats['by_stage']);
    }

    public function test_agent_detail_lists_their_opportunities(): void
    {
        $owner = $this->owner();
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id]);
        $other = Agent::factory()->create(['organization_id' => $this->organization->id]);

        $this->makeOpportunity($owner, $agent);
        $this->makeOpportunity($owner, $other);

        $this->actingAs($owner)
            ->getJson("/api/v1/agents/{$agent->uuid}/opportunities")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOpportunity(\App\Models\User $owner, Agent $agent, array $attributes = []): Opportunity
    {
        return Opportunity::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $owner->id,
            'referral_agent_id' => $agent->id,
            'pipeline_id' => $this->pipeline()->id,
            'stage_id' => $this->stage('new_lead')->id,
        ], $attributes));
    }
}
