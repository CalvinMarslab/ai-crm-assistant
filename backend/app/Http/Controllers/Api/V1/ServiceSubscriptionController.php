<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Service\Models\ServiceSubscription;
use App\Domain\Service\Services\ServiceRenewalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\SaveServiceSubscriptionRequest;
use App\Http\Resources\ServiceSubscriptionResource;
use App\Jobs\SendRenewalEmail;
use App\Support\OrganizationClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ServiceSubscriptionController extends Controller
{
    public function index(Request $request, OrganizationClock $clock): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ServiceSubscription::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:active,paused,cancelled,expiring,overdue'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $query = ServiceSubscription::query()->with(['company', 'owner']);
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhereHas('company', fn ($c) => $c->where('name', 'like', $search)));
        }
        $status = $filters['status'] ?? null;
        if ($status === 'overdue') {
            $query->where('status', 'active')->whereDate('expires_on', '<', $clock->now()->toDateString());
        } elseif ($status === 'expiring') {
            $query->where('status', 'active')->whereDate('expires_on', '>=', $clock->now()->toDateString())->whereDate('expires_on', '<=', $clock->now()->addDays(15)->toDateString());
        } elseif ($status) {
            $query->where('status', $status);
        }

        return ServiceSubscriptionResource::collection($query->orderBy('expires_on')->orderBy('id')->paginate($filters['per_page'] ?? 25));
    }

    public function summary(OrganizationClock $clock): JsonResponse
    {
        $this->authorize('viewAny', ServiceSubscription::class);
        $active = ServiceSubscription::where('status', 'active')->whereHas('company');
        $today = $clock->now()->toDateString();
        $until = $clock->now()->addDays(15)->toDateString();

        return response()->json(['data' => [
            'active' => (clone $active)->count(),
            'expiring' => (clone $active)->whereDate('expires_on', '>=', $today)->whereDate('expires_on', '<=', $until)->count(),
            'overdue' => (clone $active)->whereDate('expires_on', '<', $today)->count(),
            'pending' => (clone $active)->whereDate('expires_on', '<=', $until)->whereNotNull('amount')->selectRaw('currency, SUM(amount) AS amount')->groupBy('currency')->get()->map(fn ($row) => ['currency' => $row->currency, 'amount' => (string) $row->amount]),
            'pending_price_count' => (clone $active)->whereDate('expires_on', '<=', $until)->whereNull('amount')->count(),
            'email_enabled' => (bool) config('renewals.email_enabled') && ! in_array(config('mail.default'), ['log', 'array'], true),
            'default_internal_email' => config('renewals.internal_email'),
        ]]);
    }

    public function store(SaveServiceSubscriptionRequest $request, ServiceRenewalService $renewals): JsonResponse
    {
        return (new ServiceSubscriptionResource($renewals->save($request->validated())))->response()->setStatusCode(201);
    }

    public function show(ServiceSubscription $service): ServiceSubscriptionResource
    {
        $this->authorize('view', $service);

        return new ServiceSubscriptionResource($service->load(['company', 'owner', 'payments', 'deliveries']));
    }

    public function update(SaveServiceSubscriptionRequest $request, ServiceSubscription $service, ServiceRenewalService $renewals): ServiceSubscriptionResource
    {
        return new ServiceSubscriptionResource($renewals->save($request->validated(), $service));
    }

    public function renew(Request $request, ServiceSubscription $service, ServiceRenewalService $renewals, OrganizationClock $clock): ServiceSubscriptionResource
    {
        $this->authorize('update', $service);
        $attributes = $request->validate([
            'cycle_id' => ['required', 'uuid'],
            'expected_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'expected_currency' => ['required', 'in:MYR,SGD,USD'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$clock->now()->toDateString()],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        return new ServiceSubscriptionResource($renewals->renew($service, $attributes));
    }

    public function retry(Request $request, ServiceSubscription $service, string $delivery): JsonResponse
    {
        $this->authorize('update', $service);
        abort_unless(config('renewals.email_enabled'), 422, 'Email delivery is not enabled.');
        DB::transaction(function () use ($service, $delivery) {
            $service = ServiceSubscription::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $record = $service->deliveries()->whereUuid($delivery)->lockForUpdate()->firstOrFail();
            abort_unless($service->status === 'active' && $record->cycle_id === $service->cycle_id && in_array($record->status, ['failed', 'uncertain'], true), 422, 'Only a failed or uncertain reminder for the current active cycle can be retried.');
            abort_unless($service->hasRenewalTerms() && ($record->audience !== 'customer' || $service->canNotifyCustomer()), 422, 'Complete the details required for this reminder before retrying.');
            $record->update(['status' => 'queued', 'error' => null]);
            SendRenewalEmail::dispatch($record->id, $service->organization_id)->afterCommit();
        });

        return response()->json(['message' => 'Email queued for retry.']);
    }
}
