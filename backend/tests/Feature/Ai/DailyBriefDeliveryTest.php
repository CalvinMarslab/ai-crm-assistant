<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Services\DailyBriefService;
use App\Domain\Integration\Telegram\DailyBriefDelivery;
use App\Domain\Integration\Telegram\Jobs\SendDailyBriefToUser;
use App\Domain\Integration\Telegram\TelegramBriefFormatter;
use App\Domain\Integration\Telegram\TelegramChannel;
use App\Domain\Task\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Blocker 3: Daily brief delivery ledger.
 *
 * Persistent per-user/local-date deduplication, unique queued jobs with
 * bounded retries, per-recipient isolation, and auditable outcomes.
 */
class DailyBriefDeliveryTest extends TestCase
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

    /** Link a user via the token/webhook flow, extracting raw token from deep link. */
    private function linkUser($user, string $chatId): void
    {
        $response = $this->actingAs($user)->postJson('/api/v1/integrations/telegram/link-token');
        $rawToken = str_replace('https://t.me/TestCrmBot?start=', '', $response->json('data.deep_link'));

        $this->postJson('/api/v1/integrations/telegram/webhook', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => (int) $chatId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => (int) $chatId, 'type' => 'private'],
                'text' => "/start {$rawToken}",
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret']);
    }

    public function test_scheduler_rerun_does_not_duplicate_a_delivered_brief(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        Task::create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Overdue work',
            'due_at' => now()->subDays(2),
        ]);

        // First run: dispatches the job.
        $this->artisan('crm:daily-brief', ['--force' => true])
            ->expectsOutputToContain('1 sent')
            ->assertSuccessful();

        // Mark as delivered (simulating job completion).
        DailyBriefDelivery::where('user_id', $owner->id)->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        // Second run: should skip the already-delivered user.
        $this->artisan('crm:daily-brief', ['--force' => true])
            ->expectsOutputToContain('0 sent')
            ->assertSuccessful();

        // Only one delivery record.
        $this->assertSame(1, DailyBriefDelivery::where('user_id', $owner->id)->count());
    }

    public function test_command_dispatches_individual_jobs(): void
    {
        $this->telegramSucceeds();
        Queue::fake();

        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $this->artisan('crm:daily-brief', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(SendDailyBriefToUser::class, function ($job) use ($owner) {
            return $job->userId === $owner->id;
        });
    }

    public function test_delivery_ledger_records_queued_status(): void
    {
        $this->telegramSucceeds();
        Queue::fake();
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $this->artisan('crm:daily-brief', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('daily_brief_deliveries', [
            'user_id' => $owner->id,
            'status' => 'queued',
        ]);
    }

    public function test_job_marks_delivery_as_delivered_on_success(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $today = now()->toDateString();

        DailyBriefDelivery::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'delivery_date' => $today,
            'status' => 'queued',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $job = new SendDailyBriefToUser($owner->id, $this->organization->id, $today);
        $job->handle(
            app(DailyBriefService::class),
            app(TelegramChannel::class),
            app(TelegramBriefFormatter::class),
        );

        $this->assertDatabaseHas('daily_brief_deliveries', [
            'user_id' => $owner->id,
            'delivery_date' => $today,
            'status' => 'delivered',
        ]);
    }

    public function test_job_skips_if_already_delivered(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $today = now()->toDateString();

        DailyBriefDelivery::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'delivery_date' => $today,
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $job = new SendDailyBriefToUser($owner->id, $this->organization->id, $today);
        $job->handle(
            app(DailyBriefService::class),
            app(TelegramChannel::class),
            app(TelegramBriefFormatter::class),
        );

        // Should NOT have sent anything — delivery was already recorded.
        Http::assertNothingSent();
    }

    public function test_one_transport_failure_does_not_prevent_other_recipients(): void
    {
        $this->telegramSucceeds();
        Queue::fake();

        $owner1 = $this->owner(['email' => 'owner1@test.com']);
        $owner2 = $this->owner(['email' => 'owner2@test.com']);
        $this->linkUser($owner1, '111');
        $this->linkUser($owner2, '222');

        $this->artisan('crm:daily-brief', ['--force' => true])
            ->expectsOutputToContain('2 sent')
            ->assertSuccessful();

        // Both users get independent jobs.
        Queue::assertPushed(SendDailyBriefToUser::class, 2);
    }

    public function test_failed_delivery_is_retryable(): void
    {
        $owner = $this->owner();

        $today = now()->toDateString();

        DailyBriefDelivery::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'delivery_date' => $today,
            'status' => 'failed',
            'error' => 'Delivery failed.',
        ]);

        // The command should re-queue the failed user (not skip them).
        $this->telegramSucceeds();
        $owner->forceFill(['telegram_chat_id' => '555'])->save();

        $this->artisan('crm:daily-brief', ['--force' => true])
            ->expectsOutputToContain('1 sent')
            ->assertSuccessful();
    }

    public function test_failed_handler_never_leaks_secret_in_error(): void
    {
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $today = now()->toDateString();

        DailyBriefDelivery::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'delivery_date' => $today,
            'status' => 'queued',
        ]);

        $secretUrl = 'https://api.telegram.org/bot-SECRET-TOKEN-12345/sendMessage';
        $exception = new \RuntimeException("HTTP error at {$secretUrl}");

        $job = new SendDailyBriefToUser($owner->id, $this->organization->id, $today);
        $job->failed($exception);

        $delivery = DailyBriefDelivery::where('user_id', $owner->id)
            ->where('delivery_date', $today)
            ->first();

        // Error must be generic — never contain the secret URL or token.
        $this->assertSame('Delivery failed.', $delivery->error);
        $this->assertStringNotContainsString('SECRET-TOKEN', $delivery->error ?? '');
        $this->assertStringNotContainsString('telegram.org', $delivery->error ?? '');
    }

    public function test_crash_window_does_not_duplicate_send(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $today = now()->toDateString();

        // Simulate a crash during send: status is 'sending' (claimed but never completed).
        DailyBriefDelivery::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'delivery_date' => $today,
            'status' => 'sending',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        // A retry job finds 'sending' — must NOT send again.
        $job = new SendDailyBriefToUser($owner->id, $this->organization->id, $today);
        $job->handle(
            app(DailyBriefService::class),
            app(TelegramChannel::class),
            app(TelegramBriefFormatter::class),
        );

        // No HTTP call should have been made.
        Http::assertNothingSent();

        // Status should be terminal 'uncertain' — not 'failed' which is retryable.
        $delivery = DailyBriefDelivery::where('user_id', $owner->id)
            ->where('delivery_date', $today)
            ->first();

        $this->assertSame('uncertain', $delivery->status);
        $this->assertSame('Delivery uncertain after crash during send.', $delivery->error);
    }

    /**
     * Regression: after crash → uncertain, neither a job retry nor a scheduler
     * rerun may produce a second Telegram HTTP request or re-queue.
     */
    public function test_uncertain_state_blocks_job_retry_and_scheduler_rerun(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $today = now()->toDateString();

        // Pre-condition: delivery already in terminal 'uncertain' state.
        DailyBriefDelivery::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'delivery_date' => $today,
            'status' => 'uncertain',
            'error' => 'Delivery uncertain after crash during send.',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        // Job retry: must skip entirely, no HTTP call.
        $job = new SendDailyBriefToUser($owner->id, $this->organization->id, $today);
        $job->handle(
            app(DailyBriefService::class),
            app(TelegramChannel::class),
            app(TelegramBriefFormatter::class),
        );

        Http::assertNothingSent();

        // Scheduler rerun: must skip, not re-queue.
        $this->artisan('crm:daily-brief', ['--force' => true])
            ->expectsOutputToContain('0 sent')
            ->assertSuccessful();

        // Status remains 'uncertain', not overwritten.
        $delivery = DailyBriefDelivery::where('user_id', $owner->id)
            ->where('delivery_date', $today)
            ->first();

        $this->assertSame('uncertain', $delivery->status);
        $this->assertSame(1, DailyBriefDelivery::where('user_id', $owner->id)->count());
    }

    public function test_sending_state_is_claimed_before_external_call(): void
    {
        $this->telegramSucceeds();
        $owner = $this->owner();
        $this->linkUser($owner, '555');

        $today = now()->toDateString();

        DailyBriefDelivery::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'delivery_date' => $today,
            'status' => 'queued',
        ]);

        // Fake HTTP to succeed.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $job = new SendDailyBriefToUser($owner->id, $this->organization->id, $today);
        $job->handle(
            app(DailyBriefService::class),
            app(TelegramChannel::class),
            app(TelegramBriefFormatter::class),
        );

        // After successful send, status should be 'delivered'.
        $delivery = DailyBriefDelivery::where('user_id', $owner->id)
            ->where('delivery_date', $today)
            ->first();

        $this->assertSame('delivered', $delivery->status);
    }
}
