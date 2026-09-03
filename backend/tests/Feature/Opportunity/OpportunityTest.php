<?php

namespace Tests\Feature\Opportunity;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_opportunity(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Mobile App 2027',
            'company_id' => $company->uuid,
            'referral_agent_id' => $agent->uuid,
            'lead_source_code' => 'referral_agent',
            'estimated_value' => 85000,
            'next_action' => 'Schedule discovery call',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Mobile App 2027')
            ->assertJsonPath('data.stage.code', 'new_lead')
            ->assertJsonPath('data.owner.id', $owner->uuid)
            ->assertJsonPath('data.referral_agent.name', $agent->name);
    }

    public function test_opportunity_requires_company_owner_and_stage(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/v1/opportunities', ['title' => 'No company'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        // Owner and stage are supplied by the service when omitted, never left null.
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $owner = $this->owner();

        $uuid = $this->actingAs($owner)
            ->postJson('/api/v1/opportunities', ['title' => 'Defaults applied', 'company_id' => $company->uuid])
            ->assertCreated()
            ->json('data.id');

        $opportunity = Opportunity::whereUuid($uuid)->firstOrFail();

        $this->assertNotNull($opportunity->owner_user_id);
        $this->assertNotNull($opportunity->stage_id);
        $this->assertSame($owner->id, $opportunity->owner_user_id);
    }

    public function test_opportunity_can_change_stage(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
                'stage_id' => $this->stage('contacted')->id,
                'note' => 'Spoke to the procurement lead.',
            ])
            ->assertOk()
            ->assertJsonPath('data.stage.code', 'contacted');
    }

    public function test_stage_change_creates_history_record(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        // Created through the API so the opening history entry exists too.
        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'History Check',
            'company_id' => $company->uuid,
        ])->assertCreated()->json('data.id');

        $opportunity = Opportunity::whereUuid($uuid)->firstOrFail();

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('qualified')->id,
        ])->assertOk();

        $history = $this->actingAs($owner)
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}/stage-history")
            ->assertOk()
            ->json('data');

        // The creation entry plus the change.
        $this->assertCount(2, $history);
        $this->assertSame('qualified', $history[0]['to_stage']['code']);
        $this->assertSame('new_lead', $history[0]['from_stage']['code']);

        $this->assertDatabaseHas('opportunity_stage_history', [
            'opportunity_id' => $opportunity->id,
            'to_stage_id' => $this->stage('qualified')->id,
            'changed_by_user_id' => $owner->id,
        ]);
    }

    public function test_stage_change_creates_activity_timeline_record(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('contacted')->id,
        ])->assertOk();

        $timeline = $this->actingAs($owner)
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")
            ->assertOk()
            ->json('data');

        $this->assertContains('opportunity.stage_changed', array_column($timeline, 'type'));
    }

    public function test_opportunity_can_store_next_action_and_follow_up_date(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/next-action", [
                'next_action' => 'Send revised quotation',
                'next_follow_up_at' => now()->addDays(3)->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.next_action', 'Send revised quotation')
            ->assertJsonPath('data.has_next_action', true);

        $this->assertNotNull($opportunity->fresh()->next_follow_up_at);
    }

    public function test_clearing_next_action_requires_an_explicit_reason(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/next-action", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('no_action_reason');

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/next-action", [
                'no_action_reason' => 'Customer asked us to pause until Q3.',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_next_action', true);
    }

    public function test_won_opportunity_stores_won_date_and_final_value(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
                'stage_id' => $this->stage('won')->id,
                'final_value' => 120000,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'won')
            ->assertJsonPath('data.final_value', 120000);

        $fresh = $opportunity->fresh();
        $this->assertNotNull($fresh->won_at);
        $this->assertNull($fresh->lost_at);
    }

    public function test_won_opportunity_requires_a_final_value(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
                'stage_id' => $this->stage('won')->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('final_value');
    }

    public function test_lost_opportunity_requires_loss_reason(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
                'stage_id' => $this->stage('lost')->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('loss_reason');

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
                'stage_id' => $this->stage('lost')->id,
                'loss_reason' => 'Price',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'lost')
            ->assertJsonPath('data.loss_reason', 'Price');

        $this->assertNotNull($opportunity->fresh()->lost_at);
    }

    public function test_losing_an_opportunity_closes_its_outstanding_tasks(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)->postJson('/api/v1/tasks', [
            'title' => 'Chase the customer',
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
        ])->assertCreated();

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('lost')->id,
            'loss_reason' => 'Went with a competitor',
        ])->assertOk();

        $this->assertSame(0, $opportunity->fresh()->openTasks()->count());
    }

    public function test_stage_from_another_pipeline_is_rejected(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $foreignPipeline = \App\Domain\Pipeline\Models\Pipeline::create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Pipeline',
        ]);
        $foreignStage = \App\Domain\Pipeline\Models\PipelineStage::create([
            'pipeline_id' => $foreignPipeline->id,
            'name' => 'Foreign', 'code' => 'foreign', 'sequence' => 10, 'stage_type' => 'open',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", ['stage_id' => $foreignStage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage_id');
    }

    public function test_logging_a_call_updates_last_contact_and_timeline(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/notes", [
            'body' => 'Called the customer, they want a revised quote.',
            'type' => 'call.logged',
        ])->assertCreated();

        $this->assertNotNull($opportunity->fresh()->last_contact_at);

        $timeline = $this->actingAs($owner)
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")
            ->json('data');

        $this->assertContains('call.logged', array_column($timeline, 'type'));
    }

    private function makeOpportunity(\App\Models\User $owner): Opportunity
    {
        return Opportunity::factory()->create([
            'organization_id' => $this->organization->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $this->pipeline()->id,
            'stage_id' => $this->stage('new_lead')->id,
        ]);
    }
}
