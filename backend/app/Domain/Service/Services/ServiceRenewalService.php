<?php

namespace App\Domain\Service\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Company\Models\Company;
use App\Domain\Service\Models\ServicePayment;
use App\Domain\Service\Models\ServiceSubscription;
use App\Domain\Task\Enums\TaskStatus;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceRenewalService
{
    public function __construct(private readonly ActivityRecorder $activities, private readonly TaskService $tasks) {}

    public function save(array $attributes, ?ServiceSubscription $service = null): ServiceSubscription
    {
        if ($attributes['status'] === 'active' && ! (new ServiceSubscription($attributes))->hasRenewalTerms()) {
            throw ValidationException::withMessages(['status' => 'Set the currency and renewal period before activating reminders.']);
        }

        return DB::transaction(function () use ($attributes, $service) {
            $company = Company::whereUuid($attributes['company_id'])->firstOrFail();
            $attributes['company_id'] = $company->id;
            if ($service === null) {
                unset($attributes['cycle_id']);
                $attributes['cycle_id'] = (string) Str::uuid();
                $attributes['owner_user_id'] = Auth::id();
                $service = ServiceSubscription::create($attributes);
                $type = ActivityType::ServiceCreated;
            } else {
                $service = ServiceSubscription::whereKey($service->id)->lockForUpdate()->firstOrFail();
                $this->assertCurrentCycle($service, $attributes['cycle_id']);
                unset($attributes['cycle_id']);
                if ($service->company_id !== $company->id) {
                    throw ValidationException::withMessages(['company_id' => 'The company cannot be changed after a service is created.']);
                }
                $changedExpiry = $attributes['expires_on'] !== $service->expires_on->toDateString();
                if ($changedExpiry || $attributes['status'] !== 'active') {
                    $this->closeTask($service, false);
                    $attributes['collection_task_id'] = null;
                }
                if ($changedExpiry) {
                    $attributes['cycle_id'] = (string) Str::uuid();
                }
                $service->update($attributes);
                if ($service->collection_task_id) {
                    $task = Task::find($service->collection_task_id);
                    if ($task && ! $task->status->isClosed()) {
                        $this->tasks->update($task, [
                            'title' => ($service->amount === null ? 'Review renewal: ' : 'Collect renewal: ').$service->name,
                            'description' => "{$company->name} — {$service->renewalAmountLabel()}; expires {$service->expires_on->toDateString()}.",
                        ]);
                    }
                }
                $type = ActivityType::ServiceUpdated;
            }
            $this->activities->record(type: $type, subject: $service, title: "Service saved: {$service->name}", isInternal: true);

            return $service->load(['company', 'owner']);
        });
    }

    public function renew(ServiceSubscription $service, array $attributes): ServiceSubscription
    {
        return DB::transaction(function () use ($service, $attributes) {
            $service = ServiceSubscription::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentCycle($service, $attributes['cycle_id']);
            if (! $service->canRecordPayment()) {
                throw ValidationException::withMessages(['status' => 'Activate the service and set its price, currency and renewal period before recording payment.']);
            }
            if (number_format((float) $attributes['expected_amount'], 2, '.', '') !== $service->amount || $attributes['expected_currency'] !== $service->currency) {
                throw ValidationException::withMessages(['expected_amount' => 'The renewal price has changed. Refresh and confirm the current amount before recording payment.']);
            }
            if ($service->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Activate the service before recording a renewal payment.']);
            }
            $next = $service->expires_on->copy()->addMonthsNoOverflow($service->renewal_months);
            ServicePayment::create([
                'service_subscription_id' => $service->id, 'cycle_id' => $service->cycle_id,
                'amount' => $service->amount, 'currency' => $service->currency,
                'paid_on' => $attributes['paid_on'], 'reference' => $attributes['reference'] ?? null,
                'previous_expires_on' => $service->expires_on, 'next_expires_on' => $next,
                'recorded_by_user_id' => Auth::id(),
            ]);
            $this->closeTask($service, true);
            $service->update(['expires_on' => $next, 'cycle_id' => (string) Str::uuid(), 'collection_task_id' => null]);
            $this->activities->record(type: ActivityType::ServiceRenewed, subject: $service, title: "Payment received and service renewed: {$service->name}", metadata: ['amount' => $service->amount, 'currency' => $service->currency, 'expires_on' => $next->toDateString()], isInternal: true);

            return $service->load(['company', 'owner', 'payments', 'deliveries']);
        });
    }

    private function assertCurrentCycle(ServiceSubscription $service, string $cycle): void
    {
        if ($service->cycle_id !== $cycle) {
            throw ValidationException::withMessages(['cycle_id' => 'This service has changed or was already renewed. Refresh before continuing.']);
        }
    }

    private function closeTask(ServiceSubscription $service, bool $paid): void
    {
        $task = $service->collection_task_id ? Task::find($service->collection_task_id) : null;
        if ($task && ! $task->status->isClosed()) {
            if ($paid) {
                $this->tasks->complete($task);
            } else {
                $this->tasks->update($task, ['status' => TaskStatus::Cancelled]);
            }
        }
    }
}
