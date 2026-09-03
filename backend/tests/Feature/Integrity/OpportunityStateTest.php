<?php

namespace Tests\Feature\Integrity;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invalid combinations the audit calls out. Each must be impossible through the
 * API, not merely discouraged by the UI.
 */
class OpportunityStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_won_opportunity_cannot_keep_an_active_follow_up(): void
    {
        $owner = $this->owner();
        $opportunity = $this->apiOpportunity($owner, ['next_follow_up_at' => now()->addDays(5)->toIso8601String()]);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id,
            'final_value' => 10000,
        ])->assertOk();

        $fresh = $opportunity->fresh();
        $this->assertNull($fresh->next_follow_up_at);
        $this->assertNull($fresh->next_action);
    }

    public function test_lost_opportunity_cannot_keep_a_next_action(): void
    {
        $owner = $this->owner();
        $opportunity = $this->apiOpportunity($owner, ['next_action' => 'Chase pricing']);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('lost')->id,
            'loss_reason' => 'Price',
        ])->assertOk();

        $fresh = $opportunity->fresh();
        $this->assertNull($fresh->next_action);
        $this->assertNull($fresh->next_follow_up_at);
    }

    public function test_closed_opportunity_rejects_a_new_next_action(): void
    {
        $owner = $this->owner();
        $opportunity = $this->apiOpportunity($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 5000,
        ])->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/next-action", ['next_action' => 'Keep chasing'])
            ->assertStatus(422);
    }

    public function test_stage_must_belong_to_the_opportunity_pipeline(): void
    {
        $owner = $this->owner();
        $opportunity = $this->apiOpportunity($owner);

        $otherPipeline = \App\Domain\Pipeline\Models\Pipeline::create([
            'organization_id' => $this->organization->id, 'name' => 'Second Pipeline',
        ]);
        $foreignStage = \App\Domain\Pipeline\Models\PipelineStage::create([
            'pipeline_id' => $otherPipeline->id, 'name' => 'Elsewhere',
            'code' => 'elsewhere', 'sequence' => 10, 'stage_type' => 'open',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", ['stage_id' => $foreignStage->id])
            ->assertStatus(422)->assertJsonValidationErrors('stage_id');
    }

    public function test_contact_must_belong_to_the_opportunity_company_on_update(): void
    {
        $owner = $this->owner();
        $opportunity = $this->apiOpportunity($owner);

        $otherCompany = Company::factory()->create(['organization_id' => $this->organization->id]);
        $strayContact = \App\Domain\Company\Models\Contact::factory()->create([
            'organization_id' => $this->organization->id, 'company_id' => $otherCompany->id,
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/opportunities/{$opportunity->uuid}", ['primary_contact_id' => $strayContact->uuid])
            ->assertStatus(422)->assertJsonValidationErrors('primary_contact_id');
    }

    public function test_every_open_opportunity_reports_its_hygiene_state(): void
    {
        $owner = $this->owner();
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        // Healthy: company, owner, stage, source, next action.
        $healthy = $this->apiOpportunity($owner, [
            'referral_agent_id' => $agent->uuid,
            'lead_source_code' => 'website',
            'next_action' => 'Call back Tuesday',
        ]);

        $data = $this->actingAs($owner)->getJson("/api/v1/opportunities/{$healthy->uuid}")->json('data');

        $this->assertNotNull($data['company']);
        $this->assertNotNull($data['owner']);
        $this->assertNotNull($data['stage']);
        $this->assertNotNull($data['lead_source']);
        $this->assertTrue($data['has_next_action']);
        $this->assertSame([], $data['warnings'], 'A complete opportunity should raise no warnings');
    }

    public function test_an_opportunity_without_a_next_action_is_flagged_not_silently_accepted(): void
    {
        $owner = $this->owner();
        $drifting = $this->apiOpportunity($owner, ['next_action' => null]);

        $data = $this->actingAs($owner)->getJson("/api/v1/opportunities/{$drifting->uuid}")->json('data');

        $this->assertFalse($data['has_next_action']);
        $this->assertContains('no_next_action', array_column($data['warnings'], 'code'));

        // And it surfaces on the dashboard rather than needing to be hunted for.
        $dashboard = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');
        $this->assertContains($drifting->uuid, array_column($dashboard['sections']['without_next_action'], 'id'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apiOpportunity(\App\Models\User $owner, array $attributes = []): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', array_merge([
            'title' => 'State Check',
            'company_id' => $company->uuid,
        ], $attributes))->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }
}
