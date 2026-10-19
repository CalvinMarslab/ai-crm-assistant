<?php

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\Service\Models\ServiceSubscription;
use App\Domain\Service\Services\RenewalReminderService;
use App\Support\OrganizationClock;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;

class SendRenewalReminders extends Command
{
    protected $signature = 'crm:renewal-reminders {--dry-run : Count eligible services without creating tasks or sending mail}';

    protected $description = 'Prepare collection tasks and Email reminders from 15 days before service expiry';

    public function handle(RenewalReminderService $reminders, OrganizationClock $clock): int
    {
        $previous = OrganizationContext::id();
        $count = 0;
        try {
            foreach (Organization::where('status', 'active')->lazyById(100) as $organization) {
                OrganizationContext::set($organization->id);
                $clock->reset();
                $services = ServiceSubscription::where('status', 'active')->whereHas('company')->whereDate('expires_on', '<=', $clock->now()->addDays(15)->toDateString());
                foreach ($services->lazyById(100) as $service) {
                    if (! $this->option('dry-run')) {
                        $reminders->prepare($service);
                    }
                    $count++;
                }
            }
        } finally {
            OrganizationContext::set($previous);
            $clock->reset();
        }
        $this->info("Eligible services: {$count}.");

        return self::SUCCESS;
    }
}
