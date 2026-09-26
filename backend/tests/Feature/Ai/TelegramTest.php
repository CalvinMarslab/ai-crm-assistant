<?php

namespace Tests\Feature\Ai;

use App\Domain\Agent\Models\Agent;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Integration\Telegram\TelegramClient;
use App\Domain\Task\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** TELEGRAM_INTEGRATION.md: outbound only, and only to accounts the user linked. */
class TelegramTest extends TestCase
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

    /** Telegram accepts everything. */
    private function telegramSucceeds(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** Link a user via the token/webhook flow, extracting the raw token from the deep link. */
    private function linkUser($user, string $chatId = '123456789', string $username = 'calvin'): void
    {
        $response = $this->actingAs($user)->postJson('/api/v1/integrations/telegram/link-token');
        $rawToken = str_replace('https://t.me/TestCrmBot?start=', '', $response->json('data.deep_link'));

        $this->postJson('/api/v1/integrations/telegram/webhook', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => (int) $chatId, 'is_bot' => false, 'first_name' => 'Test', 'username' => $username],
                'chat' => ['id' => (int) $chatId, 'type' => 'private'],
                'text' => "/start {$rawToken}",
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret']);

        $user->refresh();
    }

    public function test_a_user_links_their_own_chat_via_token_and_receives_a_confirmation(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $this->linkUser($owner, '123456789', 'calvin');

        $this->assertSame('123456789', $owner->fresh()->telegram_chat_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '123456789');
    }

    public function test_unlinking_stops_delivery(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $this->linkUser($owner, '111');
        $this->actingAs($owner)->deleteJson('/api/v1/integrations/telegram/link')->assertOk();

        $this->assertNull($owner->fresh()->telegram_chat_id);

        $this->actingAs($owner)->postJson('/api/v1/integrations/telegram/test-brief')->assertStatus(422);
    }

    public function test_a_referral_agent_cannot_link_telegram(): void
    {
        $this->telegramSucceeds();
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))
            ->postJson('/api/v1/integrations/telegram/link-token')
            ->assertForbidden();
    }

    public function test_the_scheduled_brief_reaches_only_linked_active_users(): void
    {
        $this->telegramSucceeds();
        $linked = $this->owner(['email' => 'linked@example.test']);
        $this->owner(['email' => 'unlinked@example.test']);

        $this->linkUser($linked, '555');

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $linked->id,
            'assigned_user_id' => $linked->id,
            'title' => 'Overdue work',
            'due_at' => now()->subDays(2),
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('crm:daily-brief', ['--force' => true])
            ->expectsOutputToContain('1 sent')
            ->assertSuccessful();
    }

    public function test_the_brief_message_names_what_needs_doing(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->linkUser($owner, '777');

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Call the supplier back',
            'due_at' => now()->subDay(),
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->actingAs($owner)->postJson('/api/v1/integrations/telegram/test-brief')
            ->assertOk()
            ->assertJsonPath('data.delivered', true);

        Http::assertSent(function ($request) {
            return str_contains($request['text'], 'Call the supplier back')
                && str_contains($request['text'], 'Start here');
        });
    }

    public function test_nothing_is_sent_when_no_bot_is_configured(): void
    {
        $this->app->instance(TelegramClient::class, new TelegramClient(''));

        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson('/api/v1/integrations/telegram/link-token')
            ->assertStatus(422);
    }
}
