<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentStatsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreAgentRequest;
use App\Http\Requests\Agent\UpdateAgentRequest;
use App\Http\Resources\AgentResource;
use App\Http\Resources\OpportunityResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentController extends Controller
{
    public function __construct(private readonly AgentStatsService $stats) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Agent::class);

        $agents = Agent::query()
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('company_name', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            // An agent with only "view own" permission sees just their own profile.
            ->unless($request->user()->canDo(\App\Domain\Identity\Enums\PermissionCode::AgentViewAll),
                fn (Builder $q) => $q->where('user_id', $request->user()->id))
            ->withCount('opportunities')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return AgentResource::collection($agents);
    }

    public function store(StoreAgentRequest $request): JsonResponse
    {
        $this->authorize('create', Agent::class);

        $agent = Agent::create($request->validated());

        return (new AgentResource($agent))->response()->setStatusCode(201);
    }

    public function show(Agent $agent): AgentResource
    {
        $this->authorize('view', $agent);

        return new AgentResource($agent->loadCount('opportunities'), $this->stats->for($agent));
    }

    public function update(UpdateAgentRequest $request, Agent $agent): AgentResource
    {
        $this->authorize('update', $agent);

        $agent->update($request->validated());

        return new AgentResource($agent->fresh());
    }

    public function destroy(Agent $agent): JsonResponse
    {
        $this->authorize('delete', $agent);

        $agent->delete();

        return response()->json(null, 204);
    }

    public function stats(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        return response()->json(['data' => $this->stats->for($agent)]);
    }

    public function opportunities(Agent $agent): AnonymousResourceCollection
    {
        $this->authorize('view', $agent);

        return OpportunityResource::collection(
            $agent->opportunities()
                ->with(['company:id,uuid,name', 'stage', 'owner:id,uuid,name'])
                ->orderByDesc('created_at')
                ->get()
        );
    }
}
