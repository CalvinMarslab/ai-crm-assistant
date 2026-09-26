<?php

namespace Tests\Feature\Ai;

use App\Domain\Agent\Models\Agent;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Integration\Telegram\TelegramClient;
use App\Domain\Integration\Telegram\TelegramLinkToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Blocker 1: Telegram linking via expiring, single-use random link token.
 *
 * Replaces arbitrary chat_id pasting with a flow where only an inbound
 * Telegram webhook carrying /start TOKEN from the Telegram account may
 * establish the link.
 */
class TelegramLinkTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.telegram.bot_token', 'test-token');
        config()->set('ai.telegram.bot_username', 'TestCrmBot');
        config()->set('ai.telegram.webhook_secret', 'test-webhook-secret');
        Http::preventStrayRequests();
    }

    private function telegramSucceeds(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** Simulate a Telegram webhook update with /start TOKEN from a private, non-bot chat. */
    private function webhookUpdate(
        string $token,
        string $chatId = '123456789',
        ?string $username = 'calvin',
        string $chatType = 'private',
        bool $isBot = false,
        ?string $fromId = null,
    ): array {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => (int) ($fromId ?? $chatId), 'is_bot' => $isBot, 'first_name' => 'Test', 'username' => $username],
                'chat' => ['id' => (int) $chatId, 'type' => $chatType],
                'text' => "/start {$token}",
            ],
        ];
    }

    /** Extract raw token from the deep_link returned by the API. */
    private function rawTokenFromDeepLink(string $deepLink): string
    {
        return str_replace('https://t.me/TestCrmBot?start=', '', $deepLink);
    }

    /** Request a link token and return the raw token string. */
    private function requestRawToken(User $user): string
    {
        $response = $this->actingAs($user)->postJson('/api/v1/integrations/telegram/link-token')->assertOk();

        return $this->rawTokenFromDeepLink($response->json('data.deep_link'));
    }

    // ---- Token request ----

    public function test_authenticated_user_can_request_a_link_token(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/integrations/telegram/link-token')
            ->assertOk();

        $this->assertStringContainsString('https://t.me/TestCrmBot?start=', $response->json('data.deep_link'));
        $this->assertNotNull($response->json('data.expires_at'));
        $this->assertDatabaseHas('telegram_link_tokens', ['user_id' => $owner->id]);
    }

    public function test_requesting_a_new_token_expires_the_previous_one(): void
    {
        $owner = $this->owner();

        $this->requestRawToken($owner);
        $firstRecord = TelegramLinkToken::where('user_id', $owner->id)->first();

        $this->requestRawToken($owner);

        $this->assertTrue($firstRecord->fresh()->hasExpired());
    }

    public function test_token_request_requires_telegram_permission(): void
    {
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))
            ->postJson('/api/v1/integrations/telegram/link-token')
            ->assertForbidden();
    }

    public function test_token_request_fails_when_bot_not_configured(): void
    {
        $this->app->instance(TelegramClient::class, new TelegramClient(''));

        $this->actingAs($this->owner())
            ->postJson('/api/v1/integrations/telegram/link-token')
            ->assertStatus(422);
    }

    // ---- Webhook ----

    public function test_webhook_with_valid_token_links_the_account(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $rawToken = $this->requestRawToken($owner);

        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $owner->refresh();
        $this->assertSame('123456789', $owner->telegram_chat_id);
        $this->assertSame('calvin', $owner->telegram_username);
        $this->assertNotNull($owner->telegram_linked_at);

        // Confirmation message sent via Telegram.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '123456789');

        // Token consumed — only hash is stored, never raw.
        $record = TelegramLinkToken::where('user_id', $owner->id)->first();
        $this->assertNotNull($record->used_at);
        $this->assertSame(TelegramLinkToken::hashToken($rawToken), $record->token_hash);
    }

    public function test_webhook_rejects_invalid_secret(): void
    {
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate('any-token'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret'],
        )->assertForbidden();
    }

    public function test_webhook_rejects_missing_secret(): void
    {
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate('any-token'),
        )->assertForbidden();
    }

    public function test_expired_token_is_not_redeemable(): void
    {
        $owner = $this->owner();

        $rawToken = $this->requestRawToken($owner);
        TelegramLinkToken::where('user_id', $owner->id)->first()
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        // Link was NOT established.
        $this->assertNull($owner->fresh()->telegram_chat_id);
    }

    public function test_used_token_cannot_be_replayed(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $rawToken = $this->requestRawToken($owner);

        // First use succeeds.
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        // Unlink first, then try to replay the same token.
        $owner->refresh();
        $owner->forceFill(['telegram_chat_id' => null])->save();

        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken, '999999'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        // Replay did NOT re-link.
        $this->assertNull($owner->fresh()->telegram_chat_id);
    }

    public function test_chat_uniqueness_across_users(): void
    {
        $this->telegramSucceeds();
        $owner1 = $this->owner(['email' => 'owner1@example.test']);
        $owner2 = $this->owner(['email' => 'owner2@example.test']);

        // Link owner1 to chat 111.
        $rawToken1 = $this->requestRawToken($owner1);
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken1, '111'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $this->assertSame('111', $owner1->fresh()->telegram_chat_id);

        // Now owner2 tries to link the SAME chat 111.
        $rawToken2 = $this->requestRawToken($owner2);
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken2, '111'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        // Owner2 should NOT be linked.
        $this->assertNull($owner2->fresh()->telegram_chat_id);
        // Owner1 remains linked.
        $this->assertSame('111', $owner1->fresh()->telegram_chat_id);
    }

    public function test_non_start_webhook_messages_are_ignored(): void
    {
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 123], 'text' => 'Hello']],
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();
    }

    public function test_invalid_token_value_is_safely_ignored(): void
    {
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate('nonexistent-token-value'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();
    }

    // ---- Unlink ----

    public function test_unlinking_clears_telegram_fields(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $rawToken = $this->requestRawToken($owner);
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $this->assertNotNull($owner->fresh()->telegram_chat_id);

        // Unlink.
        $owner->refresh();
        $this->actingAs($owner)->deleteJson('/api/v1/integrations/telegram/link')->assertOk();
        $this->assertNull($owner->fresh()->telegram_chat_id);

        // Test brief should fail.
        $this->actingAs($owner)->postJson('/api/v1/integrations/telegram/test-brief')->assertStatus(422);
    }

    public function test_unlink_is_audited(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $rawToken = $this->requestRawToken($owner);
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        );

        $owner->refresh();
        $this->actingAs($owner)->deleteJson('/api/v1/integrations/telegram/link');

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.telegram.unlinked']);
    }

    // ---- Status after linking ----

    public function test_status_reflects_linked_state_after_webhook_completes(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        // Before linking.
        $this->actingAs($owner)->getJson('/api/v1/integrations/telegram')
            ->assertOk()
            ->assertJsonPath('data.linked', false);

        // Link via webhook.
        $rawToken = $this->requestRawToken($owner);
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        );

        $owner->refresh();

        // After linking — must reflect the linked state.
        $this->actingAs($owner)->getJson('/api/v1/integrations/telegram')
            ->assertOk()
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.username', 'calvin');
    }

    // ---- telegram_linked_at cast ----

    public function test_telegram_linked_at_is_cast_to_datetime(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $rawToken = $this->requestRawToken($owner);
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        );

        $owner->refresh();
        $this->assertInstanceOf(Carbon::class, $owner->telegram_linked_at);
    }

    // ---- Blocker A new tests: private/bot/mismatch/hash/uniqueness ----

    public function test_webhook_rejects_group_chat(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $rawToken = $this->requestRawToken($owner);

        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken, '123456789', 'calvin', chatType: 'group'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $this->assertNull($owner->fresh()->telegram_chat_id);
    }

    public function test_webhook_rejects_channel_update(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $rawToken = $this->requestRawToken($owner);

        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken, '123456789', 'calvin', chatType: 'channel'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $this->assertNull($owner->fresh()->telegram_chat_id);
    }

    public function test_webhook_rejects_bot_sender(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $rawToken = $this->requestRawToken($owner);

        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken, '123456789', 'calvin', isBot: true),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $this->assertNull($owner->fresh()->telegram_chat_id);
    }

    public function test_webhook_rejects_mismatched_sender_and_chat(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $rawToken = $this->requestRawToken($owner);

        // from.id differs from chat.id
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken, '123456789', 'calvin', fromId: '999999'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $this->assertNull($owner->fresh()->telegram_chat_id);
    }

    public function test_only_token_hash_is_persisted_never_raw(): void
    {
        $owner = $this->owner();
        $rawToken = $this->requestRawToken($owner);

        $record = TelegramLinkToken::where('user_id', $owner->id)->first();

        // The stored hash must match the SHA-256 digest.
        $this->assertSame(hash('sha256', $rawToken), $record->token_hash);

        // The raw token must not appear anywhere in the record.
        $serialized = json_encode($record->toArray());
        $this->assertStringNotContainsString($rawToken, $serialized);
    }

    public function test_db_unique_constraint_on_telegram_chat_id(): void
    {
        $this->telegramSucceeds();
        $owner1 = $this->owner(['email' => 'first@example.test']);
        $owner2 = $this->owner(['email' => 'second@example.test']);

        // Link owner1 to chat 888.
        $rawToken1 = $this->requestRawToken($owner1);
        $this->postJson(
            '/api/v1/integrations/telegram/webhook',
            $this->webhookUpdate($rawToken1, '888'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        )->assertOk();

        $this->assertSame('888', $owner1->fresh()->telegram_chat_id);

        // Directly try to set owner2 to the same chat — DB must reject.
        $this->expectException(QueryException::class);
        $owner2->forceFill(['telegram_chat_id' => '888'])->save();
    }

    // ---- Migration dedup/rollback regression ----

    /**
     * Regression: the migration must safely deduplicate pre-existing duplicate
     * telegram_chat_id values, back up cleared rows, and restore them on rollback.
     *
     * Simulates the migration's dedup logic directly since RefreshDatabase runs
     * the migration before tests, meaning no duplicates can exist at that point.
     */
    public function test_migration_dedup_and_rollback_for_duplicate_chat_ids(): void
    {
        // Step 1: Drop the unique index so we can insert duplicates.
        Schema::table('users', function ($table) {
            $table->dropUnique(['telegram_chat_id']);
        });

        // Create users with duplicate telegram_chat_id (pre-migration state).
        $user1 = User::factory()->create([
            'organization_id' => $this->organization->id,
            'telegram_chat_id' => '999',
            'telegram_linked_at' => '2026-01-01 10:00:00',
        ]);
        $user2 = User::factory()->create([
            'organization_id' => $this->organization->id,
            'telegram_chat_id' => '999',
            'telegram_linked_at' => '2026-02-01 10:00:00',
        ]);
        $user3 = User::factory()->create([
            'organization_id' => $this->organization->id,
            'telegram_chat_id' => '999',
            'telegram_linked_at' => null,
        ]);

        // Truncate the backup table for a clean slate.
        DB::table('telegram_chat_id_conflicts')->truncate();

        // Step 2: Run the migration's dedup logic.
        $this->runMigrationDedup();

        // Step 3: Verify: user1 (earliest linked_at) is the winner.
        $this->assertSame('999', $user1->fresh()->telegram_chat_id);
        $this->assertNull($user2->fresh()->telegram_chat_id);
        $this->assertNull($user3->fresh()->telegram_chat_id);

        // Backup table has the two losers.
        $conflicts = DB::table('telegram_chat_id_conflicts')->get();
        $this->assertCount(2, $conflicts);
        $backedUpUserIds = $conflicts->pluck('user_id')->sort()->values()->all();
        $expectedLosers = collect([$user2->id, $user3->id])->sort()->values()->all();
        $this->assertSame($expectedLosers, $backedUpUserIds);

        foreach ($conflicts as $conflict) {
            $this->assertSame('999', $conflict->chat_id);
        }

        // Step 4: Re-add the unique index (should succeed now).
        Schema::table('users', function ($table) {
            $table->unique('telegram_chat_id');
        });

        // Step 5: Simulate rollback — restore backed-up values.
        Schema::table('users', function ($table) {
            $table->dropUnique(['telegram_chat_id']);
        });

        foreach ($conflicts as $conflict) {
            DB::table('users')
                ->where('id', $conflict->user_id)
                ->update([
                    'telegram_chat_id' => $conflict->chat_id,
                    'telegram_linked_at' => $conflict->telegram_linked_at,
                ]);
        }

        // Verify restoration: all three users have the chat_id again.
        $this->assertSame('999', $user1->fresh()->telegram_chat_id);
        $this->assertSame('999', $user2->fresh()->telegram_chat_id);
        $this->assertSame('999', $user3->fresh()->telegram_chat_id);

        // Clean up: re-dedup and re-add the unique index for other tests.
        $this->runMigrationDedup();
        Schema::table('users', function ($table) {
            $table->unique('telegram_chat_id');
        });
    }

    /**
     * Run the same dedup logic the migration uses.
     */
    private function runMigrationDedup(): void
    {
        $duplicates = DB::table('users')
            ->select('telegram_chat_id')
            ->whereNotNull('telegram_chat_id')
            ->groupBy('telegram_chat_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('telegram_chat_id');

        foreach ($duplicates as $chatId) {
            $rows = DB::table('users')
                ->where('telegram_chat_id', $chatId)
                ->orderByRaw('telegram_linked_at IS NULL, telegram_linked_at ASC')
                ->orderBy('id')
                ->get(['id', 'telegram_chat_id', 'telegram_linked_at', 'created_at', 'updated_at']);

            $losers = $rows->skip(1);

            foreach ($losers as $loser) {
                DB::table('telegram_chat_id_conflicts')->insert([
                    'user_id' => $loser->id,
                    'chat_id' => $loser->telegram_chat_id,
                    'telegram_linked_at' => $loser->telegram_linked_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('users')
                    ->where('id', $loser->id)
                    ->update([
                        'telegram_chat_id' => null,
                        'telegram_linked_at' => null,
                    ]);
            }
        }
    }
}
