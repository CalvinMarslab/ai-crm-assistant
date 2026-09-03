<?php

namespace Tests\Feature\Workflow;

use App\Domain\Agent\Models\Agent;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Enums\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Phase 1 completion condition: an owner runs the whole lifecycle from
 * referral to won or lost without a separate to-do list. Each test walks the
 * flow through the HTTP API exactly as the UI does.
 */
class LeadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** CRM_WORKFLOW.md section 1. */
    public function test_referral_lead_flow_end_to_end(): void
    {
        $owner = $this->owner();
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Brian Tan']);

        $company = $this->actingAs($owner)->postJson('/api/v1/companies', [
            'name' => 'Lifecycle Sdn Bhd',
            'industry' => 'Manufacturing',
        ])->assertCreated()->json('data');

        $contact = $this->actingAs($owner)->postJson('/api/v1/contacts', [
            'company_id' => $company['id'],
            'name' => 'Wong Mei Ling',
            'job_title' => 'Operations Director',
            'is_primary' => true,
        ])->assertCreated()->json('data');

        $opportunity = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Website Revamp 2026',
            'company_id' => $company['id'],
            'primary_contact_id' => $contact['id'],
            'referral_agent_id' => $agent['uuid'] ?? $agent->uuid,
            'lead_source_code' => 'referral_agent',
            'estimated_value' => 68000,
            'next_action' => 'Book the discovery call',
            'next_follow_up_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()->json('data');

        // Source, agent, owner and next action are all recorded at creation.
        $this->assertSame('new_lead', $opportunity['stage']['code']);
        $this->assertSame($owner->uuid, $opportunity['owner']['id']);
        $this->assertSame('Brian Tan', $opportunity['referral_agent']['name']);
        $this->assertSame('Referral Agent', $opportunity['lead_source']['name']);
        $this->assertTrue($opportunity['has_next_action']);

        // A follow-up task attached to the opportunity.
        $this->actingAs($owner)->postJson('/api/v1/tasks', [
            'title' => 'Call Mei Ling',
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity['id'],
            'due_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated();

        // The timeline records creation and the task.
        $types = array_column(
            $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity['id']}/timeline")->json('data'),
            'type',
        );
        $this->assertContains('opportunity.created', $types);
        $this->assertContains('task.created', $types);

        // And the company timeline shows the same story.
        $companyTypes = array_column(
            $this->actingAs($owner)->getJson("/api/v1/companies/{$company['id']}/timeline")->json('data'),
            'type',
        );
        $this->assertContains('opportunity.created', $companyTypes);
    }

    /** CRM_WORKFLOW.md section 2: every stage, in order. */
    public function test_full_pipeline_progression_records_history_and_timeline(): void
    {
        $owner = $this->owner();
        $opportunity = $this->seedOpportunity($owner);

        $path = ['contacted', 'requirement_gathering', 'qualified', 'proposal_preparation', 'proposal_sent', 'negotiation'];

        foreach ($path as $code) {
            $this->actingAs($owner)
                ->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
                    'stage_id' => $this->stage($code)->id,
                    'note' => "Moved to {$code}",
                ])
                ->assertOk()
                ->assertJsonPath('data.stage.code', $code);
        }

        // Creation entry plus one per transition.
        $history = $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}/stage-history")->json('data');
        $this->assertCount(count($path) + 1, $history);

        $this->assertSame(
            array_reverse(array_merge(['new_lead'], $path)),
            array_column(array_column($history, 'to_stage'), 'code'),
        );

        // Every transition is on the timeline and in the audit log.
        $timeline = array_column(
            $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")->json('data'),
            'type',
        );
        $this->assertSame(count($path), count(array_filter($timeline, fn ($t) => $t === 'opportunity.stage_changed')));

        $audit = $this->actingAs($owner)->getJson("/api/v1/audit-logs/opportunity/{$opportunity->uuid}")->json('data');
        $stageAudits = array_filter($audit, fn ($e) => isset($e['after_data']['stage_id']));
        $this->assertGreaterThanOrEqual(count($path), count($stageAudits));
    }

    /** CRM_WORKFLOW.md section 5. */
    public function test_won_flow_closes_the_deal_and_preserves_history(): void
    {
        $owner = $this->owner();
        $opportunity = $this->seedOpportunity($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('proposal_sent')->id,
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/next-action", [
            'next_action' => 'Chase the quote',
            'next_follow_up_at' => now()->addDays(3)->toIso8601String(),
        ])->assertOk();

        $won = $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id,
            'final_value' => 72000,
        ])->assertOk()->json('data');

        $this->assertSame('won', $won['status']);
        $this->assertEqualsWithDelta(72000, $won['final_value'], 0.01);
        $this->assertNotNull($won['won_at']);
        $this->assertNull($won['lost_at']);

        // A closed deal must stop appearing on the action lists.
        $fresh = $opportunity->fresh();
        $this->assertNull($fresh->next_follow_up_at, 'Won deal still carries a follow-up date');
        $this->assertNull($fresh->next_action);

        $dashboard = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');
        $this->assertCount(0, $dashboard['sections']['follow_ups_due']);
        $this->assertCount(0, $dashboard['sections']['without_next_action']);

        // History survives the close.
        $this->assertGreaterThanOrEqual(3, count(
            $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}/stage-history")->json('data'),
        ));
        $this->assertContains('opportunity.won', array_column(
            $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")->json('data'),
            'type',
        ));
    }

    /** CRM_WORKFLOW.md section 4. */
    public function test_lost_flow_requires_a_reason_and_closes_open_work(): void
    {
        $owner = $this->owner();
        $opportunity = $this->seedOpportunity($owner);

        $this->actingAs($owner)->postJson('/api/v1/tasks', [
            'title' => 'Follow up on pricing',
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
            'due_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        // A reason is not optional.
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('lost')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('loss_reason');

        $lost = $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('lost')->id,
            'loss_reason' => 'Went with a competitor',
            'loss_note' => 'Incumbent vendor discounted heavily.',
        ])->assertOk()->json('data');

        $this->assertSame('lost', $lost['status']);
        $this->assertSame('Went with a competitor', $lost['loss_reason']);
        $this->assertNotNull($lost['lost_at']);

        // Outstanding sales work is closed, not left dangling.
        $this->assertSame(0, $opportunity->fresh()->openTasks()->count());
        $this->assertSame(TaskStatus::Cancelled, $opportunity->fresh()->tasks()->first()->status);

        // The record and its history remain readable.
        $this->assertGreaterThanOrEqual(2, count(
            $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}/stage-history")->json('data'),
        ));
    }

    public function test_on_hold_keeps_the_opportunity_out_of_the_won_and_lost_totals(): void
    {
        $owner = $this->owner();
        $opportunity = $this->seedOpportunity($owner, ['estimated_value' => 50000]);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('on_hold')->id,
            'note' => 'Customer paused the budget.',
        ])->assertOk()->assertJsonPath('data.status', 'hold');

        $metrics = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data.metrics');

        $this->assertSame(0, $metrics['won_count']);
        $this->assertSame(0, $metrics['lost_count']);
        $this->assertSame(1, $metrics['active_opportunities'], 'On hold still counts as active pipeline');
    }

    /**
     * Created through the API so the flow under test is the real one, including
     * the opening stage-history entry the service writes.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function seedOpportunity(\App\Models\User $owner, array $attributes = []): Opportunity
    {
        $company = \App\Domain\Company\Models\Company::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', array_merge([
            'title' => 'Pipeline Walk',
            'company_id' => $company->uuid,
            'next_action' => 'Initial contact',
        ], $attributes))->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }
}
