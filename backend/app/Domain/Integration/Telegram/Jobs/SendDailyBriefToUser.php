<?php

namespace App\Domain\Integration\Telegram\Jobs;

use App\Domain\Ai\Services\DailyBriefService;
use App\Domain\Integration\Telegram\DailyBriefDelivery;
use App\Domain\Integration\Telegram\TelegramBriefFormatter;
use App\Domain\Integration\Telegram\TelegramChannel;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one daily brief to one user.
 *
 * Unique per user+date so the queue cannot accumulate duplicates. Bounded
 * retries with exponential backoff. Per-recipient isolation: one user's
 * transport failure does not prevent later recipients from being delivered.
 */
class SendDailyBriefToUser implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $userId,
        public readonly int $organizationId,
        public readonly string $deliveryDate,
    ) {}

    public function uniqueId(): string
    {
        return "daily-brief:{$this->userId}:{$this->deliveryDate}";
    }

    public function handle(
        DailyBriefService $briefService,
        TelegramChannel $channel,
        TelegramBriefFormatter $formatter,
    ): void {
        OrganizationContext::set($this->organizationId);

        try {
            $user = User::find($this->userId);

            if ($user === null || ! $channel->canReach($user)) {
                $this->markDelivery('failed', 'Delivery failed.');

                return;
            }

            // Check the ledger — if already delivered or sending, do nothing.
            $existing = DailyBriefDelivery::where('user_id', $this->userId)
                ->where('delivery_date', $this->deliveryDate)
                ->first();

            if ($existing !== null && $existing->isDelivered()) {
                return;
            }

            // At-most-once: if already in 'sending' state (crash window), do
            // not send again — mark as terminal 'uncertain'. This status is
            // non-retryable: both the job and the scheduler will skip it forever.
            if ($existing !== null && $existing->isSending()) {
                $existing->update([
                    'status' => 'uncertain',
                    'error' => 'Delivery uncertain after crash during send.',
                ]);

                return;
            }

            // Terminal states must never be re-attempted.
            if ($existing !== null && $existing->isUncertain()) {
                return;
            }

            // Claim the 'sending' state BEFORE the external HTTP call.
            $this->markDelivery('sending');

            $brief = $briefService->for($user);
            $delivered = $channel->send($user, 'Your daily brief', $formatter->format($brief));

            if ($delivered) {
                $this->markDelivery('delivered');
            } else {
                // Let the queue retry if attempts remain.
                $this->markDelivery('failed', 'Delivery failed.');

                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                    return;
                }
            }
        } finally {
            OrganizationContext::clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        // Persist fixed generic error — never the exception message which may
        // contain URLs, tokens, or other secrets.
        $this->markDelivery('failed', 'Delivery failed.');

        // Log exception class only — never the message.
        Log::warning('Daily brief delivery failed permanently', [
            'user_id' => $this->userId,
            'date' => $this->deliveryDate,
            'error_class' => get_class($exception),
        ]);
    }

    private function markDelivery(string $status, ?string $error = null): void
    {
        DailyBriefDelivery::updateOrCreate(
            ['user_id' => $this->userId, 'delivery_date' => $this->deliveryDate],
            [
                'organization_id' => $this->organizationId,
                'status' => $status,
                'delivered_at' => $status === 'delivered' ? now() : null,
                'error' => $error,
            ],
        );
    }
}
