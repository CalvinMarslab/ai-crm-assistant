<?php

namespace Tests\Feature\Ai;

use App\Domain\Agent\Models\Agent;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Task\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Integration\Telegram\TelegramClient;
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
        Http::preventStrayRequests();
    }

    /** Telegram accepts everything. */
    private function telegramSucceeds(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_a_user_links_their_own_chat_and_receives_a_confirmation(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson('/api/v1/integrations/telegram/link', ['chat_id' => '123456789', 'username' => 'calvin'])
            ->assertOk()
            ->assertJsonPath('data.linked', true);

        $this->assertSame('123456789', $owner->fresh()->telegram_chat_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '123456789');
    }

    public function test_a_link_that_cannot_be_reached_is_not_kept(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson('/api/v1/integrations/telegram/link', ['chat_id' => '999'])
            ->assertStatus(422);

        // A half-linked account would silently swallow every future brief.
        $this->assertNull($owner->fresh()->telegram_chat_id);
    }

    public function test_a_malformed_chat_id_is_rejected(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        foreach (['not-a-number', '12; DROP TABLE users', ''] as $chatId) {
            $this->actingAs($owner)
                ->postJson('/api/v1/integrations/telegram/link', ['chat_id' => $chatId])
                ->assertStatus(422);
        }
    }

    public function test_unlinking_stops_delivery(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/v1/integrations/telegram/link', ['chat_id' => '111'])->assertOk();
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
            ->postJson('/api/v1/integrations/telegram/link', ['chat_id' => '123'])
            ->assertForbidden();
    }

    public function test_the_scheduled_brief_reaches_only_linked_active_users(): void
    {
        $this->telegramSucceeds();
        $linked = $this->owner(['email' => 'linked@example.test']);
        $this->owner(['email' => 'unlinked@example.test']);

        $this->actingAs($linked)->postJson('/api/v1/integrations/telegram/link', ['chat_id' => '555'])->assertOk();

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $linked->id,
            'assigned_user_id' => $linked->id,
            'title' => 'Overdue work',
            'due_at' => now()->subDays(2),
        ]);

        $this->artisan('crm:daily-brief', ['--force' => true])
            ->expectsOutputToContain('1 sent')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request['chat_id'] === '555'
            && str_contains($request['text'], 'Overdue work'));
    }

    public function test_the_brief_message_names_what_needs_doing(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/v1/integrations/telegram/link', ['chat_id' => '777'])->assertOk();

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Call the supplier back',
            'due_at' => now()->subDay(),
        ]);

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
            ->postJson('/api/v1/integrations/telegram/link', ['chat_id' => '123'])
            ->assertStatus(422);
    }
}
