<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Data\LlmMessage;
use App\Domain\Ai\Exceptions\AssistantUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * Blocker 4: AI chat idempotency key and sanitized provider errors.
 *
 * - Repeated request keys must not create duplicate messages/actions.
 * - HTTP/connection/timeout/malformed provider failures map to stable 503.
 * - Provider bodies, credentials, and customer content never leak.
 */
class AiChatIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new FakeLlmProvider;
        $this->app->instance(LlmProvider::class, $this->llm);
    }

    public function test_idempotency_key_prevents_duplicate_messages(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->llm->reply('First answer.');

        // First request with idempotency key.
        $response1 = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'What is overdue?', 'idempotency_key' => 'key-abc-123'],
        )->assertOk();

        $messageId1 = $response1->json('data.message.id');

        // Script a different reply — but idempotency should return the cached one.
        $this->llm->reply('Second answer.');

        $response2 = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'What is overdue?', 'idempotency_key' => 'key-abc-123'],
        )->assertOk();

        $messageId2 = $response2->json('data.message.id');

        // Same message returned.
        $this->assertSame($messageId1, $messageId2);

        // Only one user message in the conversation.
        $this->assertSame(1, AiMessage::where('conversation_id', $this->conversationInternalId($conversationId))
            ->where('role', 'user')
            ->count());
    }

    public function test_idempotency_key_via_header(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->llm->reply('Answer via header.');

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Pipeline summary'],
            ['Idempotency-Key' => 'header-key-456'],
        )->assertOk();

        $this->llm->reply('Duplicate.');

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Pipeline summary'],
            ['Idempotency-Key' => 'header-key-456'],
        )->assertOk();

        $this->assertSame(1, AiMessage::where('conversation_id', $this->conversationInternalId($conversationId))
            ->where('role', 'user')
            ->count());
    }

    public function test_different_idempotency_keys_create_separate_messages(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->llm->reply('Answer 1.');

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Question 1', 'idempotency_key' => 'key-1'],
        )->assertOk();

        $this->llm->reply('Answer 2.');

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Question 2', 'idempotency_key' => 'key-2'],
        )->assertOk();

        $this->assertSame(2, AiMessage::where('conversation_id', $this->conversationInternalId($conversationId))
            ->where('role', 'user')
            ->count());
    }

    public function test_provider_error_returns_sanitized_503(): void
    {
        $provider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = []): LlmMessage
            {
                throw AssistantUnavailable::providerError(500);
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'failing';
            }
        };

        $this->app->instance(LlmProvider::class, $provider);

        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Anything?'],
        )->assertStatus(503);

        // Response must not contain provider details.
        $body = $response->json();
        $this->assertStringNotContainsString('api_key', json_encode($body));
        $this->assertStringNotContainsString('openai', json_encode($body));
        $this->assertArrayHasKey('message', $body);
    }

    public function test_connection_exception_returns_sanitized_503(): void
    {
        $provider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = []): LlmMessage
            {
                throw new ConnectionException('Connection timed out');
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'timeout';
            }
        };

        $this->app->instance(LlmProvider::class, $provider);

        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Hello'],
        )->assertStatus(503);
    }

    public function test_provider_never_leaks_customer_content_in_response(): void
    {
        $provider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = []): LlmMessage
            {
                // Simulate an exception that includes the customer's message.
                throw new \RuntimeException('Error processing: "What about customer John Doe at Acme Corp?"');
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'leaky';
            }
        };

        $this->app->instance(LlmProvider::class, $provider);

        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'What about customer John Doe at Acme Corp?'],
        )->assertStatus(503);

        // The customer content must never appear in the response.
        $responseBody = $response->getContent();
        $this->assertStringNotContainsString('John Doe', $responseBody);
        $this->assertStringNotContainsString('Acme Corp', $responseBody);
    }

    public function test_no_idempotency_key_still_works_normally(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->llm->reply('Normal answer.');

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Normal question'],
        )->assertOk();

        $this->assertSame(1, AiMessage::where('conversation_id', $this->conversationInternalId($conversationId))
            ->where('role', 'user')
            ->count());
    }

    public function test_same_key_different_payload_returns_conflict(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->llm->reply('Answer.');

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'First message', 'idempotency_key' => 'collision-key'],
        )->assertOk();

        // Same key, different content — must return 422 conflict.
        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Different message', 'idempotency_key' => 'collision-key'],
        )->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_in_progress_turn_returns_stable_response(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);
        $internalId = $this->conversationInternalId($conversationId);

        // Simulate an in-progress turn: user message exists but no completed reply.
        AiMessage::create([
            'conversation_id' => $internalId,
            'role' => 'user',
            'content' => 'Working on it',
            'idempotency_key' => 'in-progress-key',
            'turn_id' => 'turn-in-progress',
        ]);

        // Retry with same key and content — should get "still processing".
        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Working on it', 'idempotency_key' => 'in-progress-key'],
        )->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_overlength_header_key_is_rejected(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->llm->reply('Answer.');

        $longKey = str_repeat('a', 65);

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Test'],
            ['Idempotency-Key' => $longKey],
        )->assertStatus(422);
    }

    public function test_overlength_body_key_is_rejected(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);

        $this->llm->reply('Answer.');

        $longKey = str_repeat('b', 65);

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Test', 'idempotency_key' => $longKey],
        )->assertStatus(422);
    }

    public function test_atomic_claim_prevents_duplicate_user_messages_under_race(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);
        $internalId = $this->conversationInternalId($conversationId);

        $this->llm->reply('First.')->reply('Second.');

        // First request claims the key.
        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Race test', 'idempotency_key' => 'race-key'],
        )->assertOk();

        // Second request with same key+content replays the completed response.
        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Race test', 'idempotency_key' => 'race-key'],
        )->assertOk();

        // Exactly one user message in the conversation.
        $this->assertSame(1, AiMessage::where('conversation_id', $internalId)
            ->where('role', 'user')
            ->count());
    }

    /**
     * Regression: two interleaved turns must not cross-contaminate on replay.
     *
     * Turn A starts (key-a), turn B starts and completes (key-b). Retrying
     * key-a must NOT return turn B's answer or action requests.
     */
    public function test_interleaved_turns_do_not_cross_contaminate_on_replay(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);
        $internalId = $this->conversationInternalId($conversationId);

        // Simulate turn A claimed but in-progress (no completed reply yet).
        $turnAId = fake()->uuid();
        AiMessage::create([
            'conversation_id' => $internalId,
            'role' => 'user',
            'content' => 'Question A',
            'idempotency_key' => 'key-a',
            'turn_id' => $turnAId,
        ]);

        // Turn B completes normally with a different turn_id.
        $this->llm->reply('Answer B.');

        $responseB = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Question B', 'idempotency_key' => 'key-b'],
        )->assertOk();

        $answerB = $responseB->json('data.message.content');
        $this->assertSame('Answer B.', $answerB);

        // Retry turn A — must get "still processing", NOT turn B's answer.
        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Question A', 'idempotency_key' => 'key-a'],
        )->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        // Verify turn B's assistant message has its own turn_id, not turn A's.
        $turnBReply = AiMessage::where('conversation_id', $internalId)
            ->where('role', 'assistant')
            ->whereNotNull('content')
            ->whereNull('tool_calls')
            ->latest('id')
            ->first();

        $this->assertNotNull($turnBReply->turn_id);
        $this->assertNotSame($turnAId, $turnBReply->turn_id);
    }

    /**
     * Regression: replaying a completed turn with action requests must return
     * only that turn's action requests, never a concurrent turn's proposals.
     */
    public function test_replay_returns_only_own_turns_action_requests(): void
    {
        $owner = $this->owner();
        $conversationId = $this->conversation($owner);
        $internalId = $this->conversationInternalId($conversationId);

        // Complete turn A with a tool call proposal.
        $this->llm
            ->callsTool('update_opportunity_next_action', ['reference' => 'fake-uuid', 'next_action' => 'Call A'])
            ->reply('Shall I set next action A?');

        $responseA = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Set next action A', 'idempotency_key' => 'turn-with-action'],
        )->assertOk();

        $turnAActions = $responseA->json('data.action_requests');

        // Complete turn B with a different tool call proposal.
        $this->llm
            ->callsTool('update_opportunity_next_action', ['reference' => 'fake-uuid-2', 'next_action' => 'Call B'])
            ->reply('Shall I set next action B?');

        $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Set next action B', 'idempotency_key' => 'turn-with-action-2'],
        )->assertOk();

        // Replay turn A — must get exactly turn A's action requests.
        $replay = $this->actingAs($owner)->postJson(
            "/api/v1/ai/conversations/{$conversationId}/messages",
            ['message' => 'Set next action A', 'idempotency_key' => 'turn-with-action'],
        )->assertOk();

        $replayActions = $replay->json('data.action_requests');
        $this->assertCount(count($turnAActions), $replayActions);

        if (count($turnAActions) > 0) {
            $this->assertSame($turnAActions[0]['id'], $replayActions[0]['id']);
        }
    }

    private function conversation(User $user): string
    {
        return $this->actingAs($user)->postJson('/api/v1/ai/conversations')->assertCreated()->json('data.id');
    }

    private function conversationInternalId(string $uuid): int
    {
        return AiConversation::where('uuid', $uuid)->firstOrFail()->id;
    }
}
