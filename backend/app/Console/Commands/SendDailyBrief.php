<?php

namespace App\Console\Commands;

use App\Domain\Ai\Services\DailyBriefService;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Integration\Telegram\TelegramBriefFormatter;
use App\Domain\Integration\Telegram\TelegramChannel;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationClock;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;

/**
 * The 9am Telegram brief from TELEGRAM_INTEGRATION.md.
 *
 * Runs hourly and sends to the organizations whose local time has just reached
 * the configured hour, so every tenant gets it at nine in their own morning
 * rather than nine in the server's.
 */
class SendDailyBrief extends Command
{
    protected $signature = 'crm:daily-brief
        {--organization= : Restrict to one organization id}
        {--force : Send regardless of the local hour}
        {--dry-run : Report who would receive it without sending}';

    protected $description = 'Send the daily brief to users who have linked Telegram';

    public function handle(
        DailyBriefService $brief,
        TelegramChannel $channel,
        TelegramBriefFormatter $formatter,
        OrganizationClock $clock,
    ): int {
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

            $localHour = (int) $clock->now()->format('G');

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

                // A message that says nothing needs doing is still worth
                // sending once; a silent morning is ambiguous.
                $payload = $brief->for($user);

                if (! $channel->canReach($user)) {
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("would send to {$user->email} ({$organization->name})");
                    $sent++;

                    continue;
                }

                $channel->send($user, 'Your daily brief', $formatter->format($payload))
                    ? $sent++
                    : $skipped++;
            }
        }

        OrganizationContext::clear();
        $clock->reset();

        $this->info("Daily brief: {$sent} sent, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
