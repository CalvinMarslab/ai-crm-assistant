<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Company\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\OpportunityResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function __construct(private readonly ActivityRecorder $activities) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        $companies = Company::query()
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('email', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('industry'), fn (Builder $q) => $q->where('industry', $request->string('industry')))
            ->withCount(['contacts', 'opportunities'])
            ->addSelect(['open_opportunities_value' => DB::table('opportunities')
                ->selectRaw('COALESCE(SUM(estimated_value), 0)')
                ->whereColumn('opportunities.company_id', 'companies.id')
                ->whereIn('opportunities.status', ['open', 'hold'])
                ->whereNull('opportunities.deleted_at')])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return CompanyResource::collection($companies);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        $company = Company::create($request->validated());

        $this->activities->record(
            type: ActivityType::CompanyCreated,
            subject: $company,
            title: "Company created: {$company->name}",
        );

        return (new CompanyResource($company))->response()->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        $this->authorize('view', $company);

        return new CompanyResource(
            $company->loadCount(['contacts', 'opportunities'])->load('contacts')
        );
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $this->authorize('update', $company);

        $company->update($request->validated());

        return new CompanyResource($company->fresh());
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $company->delete();

        return response()->json(null, 204);
    }

    public function contacts(Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        return ContactResource::collection(
            $company->contacts()->orderByDesc('is_primary')->orderBy('name')->get()
        );
    }

    public function opportunities(Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        return OpportunityResource::collection(
            $company->opportunities()
                ->with(['stage', 'owner', 'referralAgent', 'leadSource'])
                ->orderByDesc('created_at')
                ->get()
        );
    }

    /**
     * The unified timeline: the company's own events plus every event recorded
     * against its opportunities (PRD section 9).
     */
    public function timeline(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $activities = Activity::query()
            ->where('company_id', $company->id)
            ->visibleTo($request->user())
            ->with(['actor:id,uuid,name', 'subject'])
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 50));

        return ActivityResource::collection($activities);
    }
}
