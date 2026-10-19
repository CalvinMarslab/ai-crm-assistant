<?php

namespace App\Domain\Service\Services;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Notification\Services\Notifier;
use App\Domain\Service\Models\RenewalDelivery;
use App\Domain\Service\Models\ServiceSubscription;
use App\Domain\Task\Services\TaskService;
use App\Jobs\SendRenewalEmail;
use App\Support\OrganizationClock;
use Illuminate\Support\Facades\DB;

class RenewalReminderService
{
    public function __construct(private readonly TaskService $tasks, private readonly Notifier $notifier, private readonly OrganizationClock $clock) {}

    public function prepare(ServiceSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $service = ServiceSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $service->load(['company', 'owner']);
            if ($service->status !== 'active' || ! $service->hasRenewalTerms() || ! $service->company || ! $service->owner || $service->owner->status !== 'active' || ! $service->owner->canDo(PermissionCode::ServiceManage) || $service->expires_on->toDateString() > $this->clock->now()->addDays(15)->toDateString()) {
                return;
            }
            if (! $service->collection_task_id) {
                $task = $this->tasks->create([
                    'assigned_user_id' => $service->owner_user_id, 'created_by_user_id' => $service->owner_user_id,
                    'subject_type' => 'company', 'subject_id' => $service->company_id,
                    'title' => ($service->amount === null ? 'Review renewal: ' : 'Collect renewal: ').$service->name,
                    'description' => "{$service->company->name} — {$service->renewalAmountLabel()}; expires {$service->expires_on->toDateString()}.",
                    'due_at' => $this->clock->now()->endOfDay()->utc(), 'is_internal' => true,
                ]);
                $service->update(['collection_task_id' => $task->id]);
                $this->notifier->notify($service->owner, 'service.renewal_due', $service->amount === null ? 'Service renewal price needed' : 'Service renewal payment due', $task->description, ['service_id' => $service->uuid, 'task_uuid' => $task->uuid]);
            }
            if (! config('renewals.email_enabled')) {
                return;
            }
            foreach (['customer' => $service->customer_email, 'internal' => $service->internal_email] as $audience => $recipient) {
                if (blank($recipient) || ($audience === 'customer' && ! $service->canNotifyCustomer())) {
                    continue;
                }
                $delivery = RenewalDelivery::firstOrCreate([
                    'service_subscription_id' => $service->id, 'cycle_id' => $service->cycle_id, 'audience' => $audience, 'channel' => 'email',
                ], ['recipient' => $recipient, 'status' => 'queued']);
                if ($delivery->status === 'sending' && $delivery->updated_at->lt(now()->subMinutes(5))) {
                    $delivery->update(['status' => 'uncertain', 'error' => 'Delivery interrupted; check receipt before retrying.']);
                }
                if (in_array($delivery->status, ['failed', 'skipped'], true) && $delivery->attempts < 3) {
                    $delivery->update(['status' => 'queued', 'error' => null]);
                }
                if ($delivery->status === 'queued') {
                    SendRenewalEmail::dispatch($delivery->id, $service->organization_id)->afterCommit();
                }
            }
        });
    }
}
