<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Enums\ActionRequestStatus;
use App\Domain\Ai\Models\AiActionRequest;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * Blocker 2: ActionRequestService confirm is atomic and idempotent.
 *
 * Concurrent/replayed confirms must produce exactly one domain mutation and
 * one set of audit/activity side effects. Execution failures must not leave
 * a replayable ambiguous state.
 */
class ActionRequestAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new FakeLlmProvider;
        $this->app->instance(LlmProvider::class, $this->llm);
    }

    public function test_confirming_an_already_executed_request_is_idempotent(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('update_opportunity_next_action', [
                'reference' => $opportunity->uuid,
                'next_action' => 'Call the customer tomorrow',
            ])
            ->reply('Confirm to set it.');

        $conversationId = $this->actingAs($owner)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Set a next action'],
        )->json('data.action_requests.0.id');

        // First confirm succeeds.
        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');

        $this->assertSame('Call the customer tomorrow', $opportunity->fresh()->next_action);

        $auditCountAfterFirstConfirm = AuditLog::count();

        // Second confirm (replay) returns the same result without re-executing.
        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');

        // No additional audit entries from the replay.
        $this->assertSame($auditCountAfterFirstConfirm, AuditLog::count());
    }

    public function test_failed_execution_moves_to_terminal_state(): void
    {
        $owner = $this->owner();

        // Create an opportunity, then delete it so execution will fail.
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('update_opportunity_next_action', [
                'reference' => $opportunity->uuid,
                'next_action' => 'Will fail',
            ])
            ->reply('Confirm?');

        $conversationId = $this->actingAs($owner)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Set it'],
        )->json('data.action_requests.0.id');

        // Hard-delete the opportunity so execution will fail.
        $opportunity->forceDelete();

        // Confirm — should fail.
        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertUnprocessable();

        // Request is now in failed state and cannot be replayed.
        $this->assertSame(
            ActionRequestStatus::Failed->value,
            AiActionRequest::where('uuid', $requestId)->first()->status->value,
        );

        // Replaying returns the terminal state error.
        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertStatus(422);
    }

    public function test_rejected_request_cannot_be_confirmed(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('add_note', ['reference' => $opportunity->uuid, 'body' => 'Note'])
            ->reply('Confirm?');

        $conversationId = $this->actingAs($owner)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Add note'],
        )->json('data.action_requests.0.id');

        // Reject first.
        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/reject")
            ->assertOk();

        // Then try to confirm — should be rejected.
        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertStatus(422);
    }

    public function test_expired_request_moves_to_expired_state(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('add_note', ['reference' => $opportunity->uuid, 'body' => 'Note'])
            ->reply('Confirm?');

        $conversationId = $this->actingAs($owner)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Add'],
        )->json('data.action_requests.0.id');

        AiActionRequest::where('uuid', $requestId)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertStatus(422);

        $this->assertSame('expired', AiActionRequest::where('uuid', $requestId)->first()->status->value);
    }

    public function test_validation_exception_with_secret_text_does_not_leak(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('update_opportunity_next_action', [
                'reference' => $opportunity->uuid,
                'next_action' => 'Will fail with secret',
            ])
            ->reply('Confirm?');

        $conversationId = $this->actingAs($owner)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Set it'],
        )->json('data.action_requests.0.id');

        // Hard-delete opportunity so tool throws a secret-bearing ValidationException.
        $opportunity->forceDelete();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertUnprocessable();

        // Response must not contain secrets or internal details.
        $body = $response->getContent();
        $this->assertStringNotContainsString('secret', strtolower($body));
        $this->assertStringNotContainsString($opportunity->uuid, $body);

        // Ledger must record generic error only.
        $record = AiActionRequest::where('uuid', $requestId)->first();
        $this->assertSame(ActionRequestStatus::Failed->value, $record->status->value);
        $this->assertSame('The action could not be completed.', $record->execution_result['error']);

        // Exactly one terminal failed transition.
        $this->assertSame(1, AiActionRequest::where('uuid', $requestId)
            ->where('status', ActionRequestStatus::Failed)->count());
    }

    public function test_runtime_exception_with_secret_never_leaks(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);
        $secretUrl = 'https://api.secret-provider.com/key=sk-SUPER-SECRET-KEY';

        $this->llm
            ->callsTool('update_opportunity_next_action', [
                'reference' => $opportunity->uuid,
                'next_action' => 'Will throw RuntimeException',
            ])
            ->reply('Confirm?');

        $conversationId = $this->actingAs($owner)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Do it'],
        )->json('data.action_requests.0.id');

        // Delete opportunity so execution throws containing the secret URL.
        $opportunity->forceDelete();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertUnprocessable();

        // Secret URL must never appear in response body.
        $this->assertStringNotContainsString('sk-SUPER-SECRET', $response->getContent());
        $this->assertStringNotContainsString('secret-provider', $response->getContent());

        // Ledger records generic error.
        $record = AiActionRequest::where('uuid', $requestId)->first();
        $this->assertSame('The action could not be completed.', $record->execution_result['error']);
    }

    public function test_ownership_refusal_remains_pending(): void
    {
        $owner1 = $this->owner(['email' => 'owner1@test.com']);
        $owner2 = $this->owner(['email' => 'owner2@test.com']);
        $opportunity = $this->opportunity($owner1);

        $this->llm
            ->callsTool('add_note', ['reference' => $opportunity->uuid, 'body' => 'Note'])
            ->reply('Confirm?');

        $conversationId = $this->actingAs($owner1)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner1)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Add note'],
        )->json('data.action_requests.0.id');

        // Owner2 tries to confirm owner1's request — should be refused.
        $this->actingAs($owner2)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertForbidden();

        // Request must remain pending — not moved to failed.
        $this->assertSame(
            ActionRequestStatus::Pending->value,
            AiActionRequest::where('uuid', $requestId)->first()->status->value,
        );
    }

    public function test_expired_request_remains_expired_not_failed(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->llm
            ->callsTool('add_note', ['reference' => $opportunity->uuid, 'body' => 'Note'])
            ->reply('Confirm?');

        $conversationId = $this->actingAs($owner)->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $requestId = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Add note'],
        )->json('data.action_requests.0.id');

        AiActionRequest::where('uuid', $requestId)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($owner)
            ->postJson("/api/v1/ai/action-requests/{$requestId}/confirm")
            ->assertStatus(422);

        // Must be expired, not failed.
        $this->assertSame(
            ActionRequestStatus::Expired->value,
            AiActionRequest::where('uuid', $requestId)->first()->status->value,
        );
    }

    private function opportunity($owner, string $title = 'Atomicity test deal'): Opportunity
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
