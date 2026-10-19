<?php

namespace App\Jobs;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Service\Models\RenewalDelivery;
use App\Mail\ServiceRenewalReminder;
use App\Support\OrganizationClock;
use App\Support\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendRenewalEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $deliveryId, public readonly int $organizationId)
    {
        $this->onQueue('renewals');
    }

    public function uniqueId(): string
    {
        return "renewal-email:{$this->organizationId}:{$this->deliveryId}";
    }

    public function handle(OrganizationClock $clock): void
    {
        $previous = OrganizationContext::id();
        OrganizationContext::set($this->organizationId);
        $clock->reset();
        try {
            $delivery = DB::transaction(function () use ($clock) {
                $delivery = RenewalDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
                if (! $delivery || $delivery->status !== 'queued') {
                    return null;
                }
                $service = $delivery->subscription()->with(['company', 'owner', 'organization'])->first();
                if (! $service || $service->status !== 'active' || ! $service->hasRenewalTerms() || ($delivery->audience === 'customer' && ! $service->canNotifyCustomer()) || ($delivery->audience === 'internal' && blank($service->internal_email)) || $service->cycle_id !== $delivery->cycle_id || ! $service->company || $service->organization->status !== 'active' || ! $service->owner || $service->owner->status !== 'active' || ! $service->owner->canDo(PermissionCode::ServiceManage) || $service->expires_on->toDateString() > $clock->now()->addDays(15)->toDateString()) {
                    $delivery->update(['status' => 'skipped', 'error' => 'Service is no longer eligible for this reminder.']);

                    return null;
                }
                if (! config('renewals.email_enabled') || in_array(config('mail.default'), ['log', 'array'], true)) {
                    $delivery->update(['status' => 'failed', 'error' => 'Configure and enable a real Email transport before sending.', 'attempts' => $delivery->attempts + 1]);

                    return null;
                }
                $delivery->update(['status' => 'sending', 'error' => null, 'attempts' => $delivery->attempts + 1, 'recipient' => $delivery->audience === 'customer' ? $service->customer_email : $service->internal_email]);
                $delivery->setRelation('subscription', $service);

                return $delivery;
            });
            if (! $delivery) {
                return;
            }
            try {
                $service = $delivery->subscription;
                Mail::to($delivery->recipient)->send(new ServiceRenewalReminder(
                    $service->name, $service->company->name, $service->type, $service->expires_on->toDateString(),
                    $service->amount, $service->currency, $delivery->audience === 'internal',
                ));
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            } catch (Throwable $exception) {
                $delivery->update(['status' => 'uncertain', 'error' => 'Mail service did not confirm completion. Check receipt before retrying.']);
                Log::warning('Renewal Email delivery uncertain', ['delivery_uuid' => $delivery->uuid, 'error_class' => $exception::class]);
            }
        } finally {
            OrganizationContext::set($previous);
            $clock->reset();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = RenewalDelivery::withoutGlobalScope('organization')->where('organization_id', $this->organizationId)->find($this->deliveryId);
        if ($delivery && in_array($delivery->status, ['queued', 'sending'], true)) {
            $delivery->update(['status' => $delivery->status === 'sending' ? 'uncertain' : 'failed', 'error' => 'Delivery interrupted. Review before retrying.']);
        }
    }
}
