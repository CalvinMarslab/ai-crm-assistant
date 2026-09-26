<?php

namespace App\Console\Commands;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Integration\Telegram\DailyBriefDelivery;
use App\Domain\Integration\Telegram\Jobs\SendDailyBriefToUser;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationClock;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;

/**
 * The 9am Telegram brief from TELEGRAM_INTEGRATION.md.
 *
 * Runs hourly and queues individual jobs for organizations whose local time
 * has just reached the configured hour. Each recipient gets an isolated,
 * retryable job; the delivery ledger ensures scheduler reruns never duplicate
 * a delivered brief.
 */
class SendDailyBrief extends Command
{
    protected $signature = 'crm:daily-brief
        {--organization= : Restrict to one organization id}
        {--force : Send regardless of the local hour}
        {--dry-run : Report who would receive it without sending}';

    protected $description = 'Send the daily brief to users who have linked Telegram';

    public function handle(OrganizationClock $clock): int
    {
        $targetHour = (int) config('ai.telegram.daily_brief_hour');
        $sent = 0;
        $skipped = 0;

        $organizations = OrganizationContext::withoutScope(
            fn () => Organization::query()
                ->when($this->option('organization'), fn ($q) => $q->whereKey($this->option('organization')))
                ->where('status', 'active')
                ->get()
        );

        foreach ($organizations as $organization) {
            OrganizationContext::set($organization->id);
            $clock->reset();

            $localNow = $clock->now();
            $localHour = (int) $localNow->format('G');
            $localDate = $localNow->toDateString();

            if (! $this->option('force') && $localHour !== $targetHour) {
                continue;
            }

            $recipients = User::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->whereNotNull('telegram_chat_id')
                ->get();

            foreach ($recipients as $user) {
                if (! $user->canDo(PermissionCode::AiUse)) {
                    continue;
                }

                // Check the delivery ledger: skip terminal and in-flight states.
                // delivered, sending, and uncertain are never re-attempted.
                $existing = DailyBriefDelivery::where('user_id', $user->id)
                    ->where('delivery_date', $localDate)
                    ->first();

                if ($existing !== null && $existing->isTerminal()) {
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("would send to {$user->email} ({$organization->name})");
                    $sent++;

                    continue;
                }

                // Record a queued entry in the ledger.
                DailyBriefDelivery::updateOrCreate(
                    ['user_id' => $user->id, 'delivery_date' => $localDate],
                    ['organization_id' => $organization->id, 'status' => 'queued'],
                );

                SendDailyBriefToUser::dispatch(
                    $user->id,
                    $organization->id,
                    $localDate,
                );

                $sent++;
            }
        }

        OrganizationContext::clear();
        $clock->reset();

        $this->info("Daily brief: {$sent} sent, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
