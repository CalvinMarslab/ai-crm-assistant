<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_every_required_section(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'sections' => [
                        'overdue_tasks', 'tasks_due_today', 'follow_ups_due',
                        'without_next_action', 'proposals_awaiting_response',
                        'high_value_at_risk', 'recently_inactive',
                    ],
                    'metrics' => [
                        'leads_this_month', 'active_opportunities', 'pipeline_value',
                        'won_value', 'lost_value', 'win_rate', 'average_sales_cycle_days',
                        'overdue_task_count', 'without_next_action_count',
                    ],
                    'stage_distribution',
                    'recent_activity',
                    'meta' => ['inactivity_threshold_days', 'generated_at'],
                ],
            ]);
    }

    public function test_dashboard_shows_overdue_tasks(): void
    {
        $owner = $this->owner();

        Task::factory()->overdue()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Overdue chase',
        ]);

        $data = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');

        $this->assertCount(1, $data['sections']['overdue_tasks']);
        $this->assertSame('Overdue chase', $data['sections']['overdue_tasks'][0]['title']);
        $this->assertSame(1, $data['metrics']['overdue_task_count']);
    }

    public function test_dashboard_shows_todays_follow_ups(): void
    {
        $owner = $this->owner();

        $this->makeOpportunity($owner, [
            'title' => 'Due today',
            'next_action' => 'Call back',
            'next_follow_up_at' => now()->setTime(9, 0),
        ]);
        $this->makeOpportunity($owner, [
            'title' => 'Due next week',
            'next_action' => 'Call back',
            'next_follow_up_at' => now()->addWeek(),
        ]);

        $data = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');

        $this->assertCount(1, $data['sections']['follow_ups_due']);
        $this->assertSame('Due today', $data['sections']['follow_ups_due'][0]['title']);
    }

    public function test_dashboard_shows_opportunities_without_next_action(): void
    {
        $owner = $this->owner();

        $this->makeOpportunity($owner, ['title' => 'Drifting', 'next_action' => null, 'no_action_reason' => null]);
        $this->makeOpportunity($owner, ['title' => 'Has an action', 'next_action' => 'Send quote']);
        $this->makeOpportunity($owner, ['title' => 'Explicitly paused', 'next_action' => null, 'no_action_reason' => 'Customer on hold until Q3']);

        $data = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');

        $this->assertCount(1, $data['sections']['without_next_action']);
        $this->assertSame('Drifting', $data['sections']['without_next_action'][0]['title']);
        $this->assertSame(1, $data['metrics']['without_next_action_count']);
    }

    public function test_dashboard_shows_pipeline_value_and_stage_distribution(): void
    {
        $owner = $this->owner();

        $this->makeOpportunity($owner, ['estimated_value' => 50000, 'stage_id' => $this->stage('new_lead')->id]);
        $this->makeOpportunity($owner, ['estimated_value' => 30000, 'stage_id' => $this->stage('qualified')->id]);

        $data = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');

        $this->assertEqualsWithDelta(80000, $data['metrics']['pipeline_value'], 0.01);
        $this->assertSame(2, $data['metrics']['active_opportunities']);

        $distribution = collect($data['stage_distribution'])->keyBy('code');
        $this->assertSame(1, $distribution['new_lead']['count']);
        $this->assertSame(1, $distribution['qualified']['count']);
    }

    public function test_dashboard_shows_proposals_awaiting_response(): void
    {
        $owner = $this->owner();

        $this->makeOpportunity($owner, [
            'title' => 'Quote out',
            'quotation_status' => 'sent',
            'quotation_sent_at' => now()->subDays(4),
        ]);
        $this->makeOpportunity($owner, ['title' => 'Still drafting', 'quotation_status' => 'preparing']);

        $data = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');

        $this->assertCount(1, $data['sections']['proposals_awaiting_response']);
        $this->assertSame('Quote out', $data['sections']['proposals_awaiting_response'][0]['title']);
    }

    public function test_dashboard_shows_recent_activity_and_win_rate(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Winning deal',
            'company_id' => $company->uuid,
        ])->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$uuid}/stage", [
            'stage_id' => $this->stage('won')->id,
            'final_value' => 60000,
        ])->assertOk();

        $lost = $this->makeOpportunity($owner);
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$lost->uuid}/stage", [
            'stage_id' => $this->stage('lost')->id,
            'loss_reason' => 'Budget',
        ])->assertOk();

        $data = $this->actingAs($owner)->getJson('/api/v1/dashboard')->json('data');

        $this->assertEqualsWithDelta(50.0, $data['metrics']['win_rate'], 0.01);
        $this->assertEqualsWithDelta(60000, $data['metrics']['won_value'], 0.01);
        $this->assertNotEmpty($data['recent_activity']);
        $this->assertContains('opportunity.won', array_column($data['recent_activity'], 'type'));
    }

    public function test_hygiene_warnings_are_attached_to_opportunities(): void
    {
        $owner = $this->owner();

        $opportunity = $this->makeOpportunity($owner, [
            'next_action' => null,
            'no_action_reason' => null,
            'expected_close_date' => now()->subWeek()->toDateString(),
        ]);

        $warnings = $this->actingAs($owner)
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}")
            ->json('data.warnings');

        $codes = array_column($warnings, 'code');
        $this->assertContains('no_next_action', $codes);
        $this->assertContains('close_date_passed', $codes);
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
            'next_action' => 'Follow up',
        ], $attributes));
    }
}
