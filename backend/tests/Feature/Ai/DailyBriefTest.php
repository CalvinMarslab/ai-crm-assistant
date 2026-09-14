<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Services\DailyBriefService;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/** AI_ASSISTANT_SPEC.md section 5. */
class DailyBriefTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_brief_contains_every_section_the_specification_requires(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->getJson('/api/v1/ai/daily-brief')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'generated_at', 'for', 'timezone', 'top_priorities',
                    'sections' => [
                        'overdue_tasks', 'tasks_due_today', 'follow_ups_due',
                        'opportunities_without_next_action', 'proposals_awaiting_response',
                        'high_value_at_risk', 'projects_requiring_update',
                    ],
                    'suggested_actions', 'counts',
                ],
            ]);
    }

    public function test_the_brief_works_with_no_llm_configured(): void
    {
        // The whole point: a brief is a statement of fact, so it must not
        // depend on a model being reachable.
        $this->app->instance(LlmProvider::class, (new FakeLlmProvider)->unconfigured());

        $owner = $this->owner();
        $this->overdueTask($owner);

        $brief = $this->actingAs($owner)->getJson('/api/v1/ai/daily-brief')->assertOk()->json('data');

        $this->assertSame(1, $brief['counts']['overdue_tasks']);
        $this->assertNotEmpty($brief['top_priorities']);
    }

    public function test_the_brief_reports_the_real_state_of_the_business(): void
    {
        $owner = $this->owner();

        $this->overdueTask($owner, 'Chase the deposit');

        $dueToday = $this->opportunity($owner, 'Due today', [
            'next_action' => 'Call them', 'next_follow_up_at' => now()->toIso8601String(),
        ]);
        $drifting = $this->opportunity($owner, 'No next step');
        $quoted = $this->opportunity($owner, 'Quotation out', ['next_action' => 'Chase']);
        $quoted->update(['quotation_status' => 'sent', 'quotation_sent_at' => now()->subDays(5)]);

        $brief = $this->actingAs($owner)->getJson('/api/v1/ai/daily-brief')->json('data');

        $this->assertSame(1, $brief['counts']['overdue_tasks']);
        $this->assertSame(1, $brief['counts']['follow_ups_due']);
        $this->assertSame(1, $brief['counts']['without_next_action']);
        $this->assertSame(1, $brief['counts']['proposals_awaiting_response']);

        $this->assertContains('Chase the deposit', array_column($brief['top_priorities'], 'what'));
        $this->assertContains($drifting->title, array_column($brief['sections']['opportunities_without_next_action'], 'title'));
        $this->assertNotEmpty($brief['suggested_actions']);
    }

    public function test_the_brief_says_so_when_nothing_needs_attention(): void
    {
        $owner = $this->owner();

        $this->opportunity($owner, 'Healthy deal', [
            'next_action' => 'Meeting booked', 'next_follow_up_at' => now()->addWeek()->toIso8601String(),
        ]);

        $brief = $this->actingAs($owner)->getJson('/api/v1/ai/daily-brief')->json('data');

        $this->assertSame(0, array_sum($brief['counts']));
        $this->assertSame([], $brief['top_priorities']);
        $this->assertTrue(app(DailyBriefService::class)->isEmpty($owner->fresh('roles')));
    }

    public function test_the_brief_is_scoped_to_the_person_reading_it(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $this->overdueTask($owner, 'Owner task');

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $pm->id,
            'assigned_user_id' => $pm->id,
            'title' => 'PM task',
            'due_at' => now()->subDays(2),
        ]);

        $pmBrief = $this->actingAs($pm)->getJson('/api/v1/ai/daily-brief')->json('data');
        $titles = array_column($pmBrief['sections']['overdue_tasks'], 'title');

        $this->assertContains('PM task', $titles);
        $this->assertNotContains('Owner task', $titles, 'A manager saw the owner\'s private work');
    }

    public function test_projects_needing_attention_explain_why(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $opportunity = $this->opportunity($owner, 'Becomes a project', ['next_action' => 'x']);
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 30000,
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
            'project_manager_id' => $pm->uuid,
        ])->assertCreated();

        $brief = $this->actingAs($owner)->getJson('/api/v1/ai/daily-brief')->json('data');
        $projects = $brief['sections']['projects_requiring_update'];

        $this->assertCount(1, $projects);
        $this->assertSame('Handover checklist unfinished', $projects[0]['reason']);
    }

    private function overdueTask(User $user, string $title = 'Overdue thing'): Task
    {
        return Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $user->id,
            'assigned_user_id' => $user->id,
            'title' => $title,
            'due_at' => now()->subDays(3),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function opportunity(User $owner, string $title, array $attributes = []): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', array_merge([
            'title' => $title,
            'company_id' => $company->uuid,
            'estimated_value' => 40000,
        ], $attributes))->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }
}
