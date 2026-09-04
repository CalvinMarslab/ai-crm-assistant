<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentStatsService;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgentFacingOpportunityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The referral agent portal. Everything here is scoped to the caller's own
 * agent profile; there is no parameter through which another agent's data can
 * be requested.
 */
class PortalController extends Controller
{
    public function __construct(private readonly AgentStatsService $stats) {}

    public function summary(Request $request): JsonResponse
    {
        $agent = $this->agentFor($request);

        $stats = $this->stats->for($agent);

        return response()->json([
            'data' => [
                'agent' => [
                    'id' => $agent->uuid,
                    'name' => $agent->name,
                    'company_name' => $agent->company_name,
                    'status' => $agent->status,
                    'joined_at' => $agent->joined_at?->toDateString(),
                ],
                // Counts and won value only: pipeline value and per-stage
                // breakdowns are internal (USER_ROLES_PERMISSION.md).
                'performance' => [
                    'introduced' => $stats['introduced'],
                    'active' => $stats['active'],
                    'won' => $stats['won'],
                    'lost' => $stats['lost'],
                    'conversion_rate' => $stats['conversion_rate'],
                ],
                'status_breakdown' => $this->statusBreakdown($agent),
            ],
        ]);
    }

    public function opportunities(Request $request): AnonymousResourceCollection
    {
        $agent = $this->agentFor($request);

        $opportunities = Opportunity::query()
            ->forReferralAgent($agent->id)
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->with(['company:id,uuid,name', 'stage:id,agent_facing_status', 'project:id,opportunity_id,status'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return AgentFacingOpportunityResource::collection($opportunities);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $agent = $this->agentFor($request);

        $opportunity = Opportunity::query()
            ->forReferralAgent($agent->id)
            ->whereUuid($uuid)
            ->with(['company:id,uuid,name', 'stage:id,agent_facing_status', 'project:id,opportunity_id,status'])
            ->firstOrFail();

        // Only stage history intended for agent visibility, expressed in the
        // simplified vocabulary and de-duplicated.
        // Chronological: this reads as a progress trail for the agent, not as an
        // internal change log, so the earliest time each status was reached wins.
        $history = $opportunity->stageHistory()
            ->with('toStage:id,agent_facing_status')
            ->reorder('changed_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($entry) => [
                'status' => $entry->toStage?->agent_facing_status,
                'changed_at' => $entry->changed_at?->toIso8601String(),
            ])
            ->filter(fn ($entry) => $entry['status'] !== null)
            ->values()
            ->unique('status')
            ->values();

        return response()->json([
            'data' => [
                'opportunity' => new AgentFacingOpportunityResource($opportunity),
                'progress' => $history,
            ],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function statusBreakdown(Agent $agent): array
    {
        return Opportunity::query()
            ->forReferralAgent($agent->id)
            ->with(['stage:id,agent_facing_status', 'project:id,opportunity_id,status'])
            ->get()
            ->groupBy(fn (Opportunity $opportunity) => $opportunity->project !== null
                ? $opportunity->project->status->agentFacingStatus()
                : ($opportunity->stage?->agent_facing_status ?? 'New'))
            ->map->count()
            ->all();
    }

    private function agentFor(Request $request): Agent
    {
        $user = $request->user();

        abort_unless($user->canDo(PermissionCode::PortalAccess), 403);

        $agent = $user->agentProfile;

        abort_if($agent === null, 403, 'Your account is not linked to an agent profile.');

        return $agent;
    }
}
