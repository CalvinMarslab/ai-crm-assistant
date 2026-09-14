<?php

namespace Tests\Feature\Ai;

use App\Domain\Agent\Models\Agent;
use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Models\AiActionRequest;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * The guardrails from AI_ASSISTANT_SPEC.md section 6 and the safety flow from
 * SYSTEM_ARCHITECTURE.md section 5.
 */
class AssistantSafetyTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new FakeLlmProvider;
        $this->app->instance(LlmProvider::class, $this->llm);
    }

    public function test_a_write_tool_never_writes_during_a_conversation(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('update_opportunity_next_action', [
                'reference' => $opportunity->uuid,
                'next_action' => 'Call the customer tomorrow',
            ])
            ->reply('I can set that next action once you confirm.');

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($owner)}/messages",
            ['message' => 'Set a next action on that deal'],
        )->assertOk();

        // Proposed, not performed.
        $this->assertNull($opportunity->fresh()->next_action, 'The assistant wrote without confirmation');

        $requests = $response->json('data.action_requests');
        $this->assertCount(1, $requests);
        $this->assertSame('pending', $requests[0]['status']);
        $this->assertStringContainsString('Call the customer tomorrow', $requests[0]['summary']);
    }

    public function test_confirming_the_proposal_performs_the_write_through_the_domain_service(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('update_opportunity_next_action', [
                'reference' => $opportunity->uuid,
                'next_action' => 'Send the revised quotation',
            ])
            ->reply('Confirm and I will set it.');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($owner)}/messages",
            ['message' => 'Set the next action'],
        )->json('data.action_requests.0.id');

        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');

        $this->assertSame('Send the revised quotation', $opportunity->fresh()->next_action);

        // Went through the normal path: timeline and audit both record it.
        $types = array_column(
            $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")->json('data'),
            'type',
        );
        $this->assertContains('opportunity.next_action_changed', $types);
        $this->assertDatabaseHas('audit_logs', ['action' => 'opportunity.updated']);
    }

    public function test_a_rejected_proposal_changes_nothing(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm->callsTool('add_note', ['reference' => $opportunity->uuid, 'body' => 'Spoke to them'])
            ->reply('Confirm to record it.');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($owner)}/messages",
            ['message' => 'Log a call'],
        )->json('data.action_requests.0.id');

        $activitiesBefore = $opportunity->activities()->count();

        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame($activitiesBefore, $opportunity->fresh()->activities()->count());
    }

    public function test_a_proposal_cannot_be_confirmed_by_a_different_user(): void
    {
        $owner = $this->owner();
        $colleague = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm->callsTool('add_note', ['reference' => $opportunity->uuid, 'body' => 'Note'])->reply('Confirm?');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($owner)}/messages",
            ['message' => 'Add a note'],
        )->json('data.action_requests.0.id');

        $this->actingAs($colleague)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertForbidden();

        $this->assertSame('pending', AiActionRequest::where('uuid', $requestId)->first()->status->value);
    }

    public function test_an_expired_proposal_is_refused(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm->callsTool('add_note', ['reference' => $opportunity->uuid, 'body' => 'Note'])->reply('Confirm?');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($owner)}/messages",
            ['message' => 'Add a note'],
        )->json('data.action_requests.0.id');

        AiActionRequest::where('uuid', $requestId)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertStatus(422);

        $this->assertSame('expired', AiActionRequest::where('uuid', $requestId)->first()->status->value);
    }

    public function test_the_assistant_is_only_offered_tools_the_user_may_run(): void
    {
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $this->llm->reply('Here is what I found.');

        $this->actingAs($pm)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($pm)}/messages",
            ['message' => 'How is the pipeline?'],
        )->assertOk();

        $offered = $this->llm->lastOfferedTools();

        // A project manager has no business searching the whole pipeline or
        // changing stages, so those are never described to the model.
        $this->assertNotContains('search_opportunities', $offered);
        $this->assertNotContains('update_opportunity_stage', $offered);
        $this->assertNotContains('get_agent_performance', $offered);
        $this->assertContains('get_projects', $offered);
    }

    public function test_a_tool_the_user_cannot_run_is_refused_even_if_the_model_asks(): void
    {
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        // A model that hallucinates a tool it was never offered.
        $this->llm
            ->callsTool('update_opportunity_stage', ['reference' => $opportunity->uuid, 'stage_code' => 'won'])
            ->reply('I could not do that.');

        $response = $this->actingAs($pm)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($pm)}/messages",
            ['message' => 'Mark it won'],
        )->assertOk();

        $this->assertSame([], $response->json('data.action_requests'));
        $this->assertSame('new_lead', $opportunity->fresh()->stage->code);
        $this->assertDatabaseCount('ai_action_requests', 0);
    }

    public function test_a_referral_agent_cannot_reach_the_assistant_at_all(): void
    {
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);
        $agentUser = $agentUser->fresh(['roles', 'agentProfile']);

        $this->actingAs($agentUser)->getJson('/api/v1/ai/status')->assertForbidden();
        $this->actingAs($agentUser)->postJson('/api/v1/ai/conversations')->assertForbidden();
        $this->actingAs($agentUser)->getJson('/api/v1/ai/daily-brief')->assertForbidden();
    }

    public function test_conversations_are_private_to_the_person_who_had_them(): void
    {
        $owner = $this->owner();
        $colleague = $this->owner();

        $conversationId = $this->conversation($owner);

        $this->actingAs($colleague)->getJson("/api/v1/ai/conversations/{$conversationId}/messages")->assertForbidden();
        $this->actingAs($colleague)->postJson("/api/v1/ai/conversations/{$conversationId}/messages", [
            'message' => 'Reading someone else\'s chat',
        ])->assertForbidden();
    }

    public function test_the_assistant_reports_itself_unavailable_rather_than_inventing_a_reply(): void
    {
        $owner = $this->owner();
        $this->llm->unconfigured();

        $this->actingAs($owner)
            ->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('data.chat_available', false)
            // The brief needs no model, so it stays available.
            ->assertJsonPath('data.brief_available', true);

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$this->conversation($owner)}/messages",
            ['message' => 'Anything?'],
        )->assertStatus(422);
    }

    public function test_read_tools_return_only_what_the_user_may_see(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        // Both created by the owner, who alone may create opportunities; one is
        // then handed to the project manager.
        $mine = $this->opportunity($owner, 'Handed to the PM');
        $theirs = $this->opportunity($owner, 'Owner keeps this');

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$mine->uuid}/owner", ['owner_id' => $pm->uuid])
            ->assertOk();

        // The tool runs the same scoped queries the API does, so the assistant
        // inherits the caller's reach rather than widening it.
        $tool = app(\App\Domain\Ai\Tools\GetOpportunityTool::class);

        $this->assertNotEmpty($tool->execute($pm, ['reference' => $mine->uuid]));

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $tool->execute($pm, ['reference' => $theirs->uuid]);
    }

    private function conversation(User $user): string
    {
        return $this->actingAs($user)->postJson('/api/v1/ai/conversations')->assertCreated()->json('data.id');
    }

    private function opportunity(User $owner, string $title = 'Assistant test deal'): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => $title,
            'company_id' => $company->uuid,
            'estimated_value' => 50000,
        ])->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }
}
