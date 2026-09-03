<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Data\StageChangeData;
use App\Domain\Opportunity\Models\LeadSource;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\OpportunityService;
use App\Domain\Opportunity\Services\StageTransitionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Opportunity\AddNoteRequest;
use App\Http\Requests\Opportunity\ChangeStageRequest;
use App\Http\Requests\Opportunity\SetNextActionRequest;
use App\Http\Requests\Opportunity\StoreOpportunityRequest;
use App\Http\Requests\Opportunity\UpdateOpportunityRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\OpportunityResource;
use App\Http\Resources\StageHistoryResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly StageTransitionService $stageTransitions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Opportunity::class);

        $opportunities = $this->visibleQuery($request->user())
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('title', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', '%'.$request->string('search').'%'))))
            ->when($request->filled('stage_code'), fn (Builder $q) => $q->whereHas(
                'stage', fn (Builder $s) => $s->where('code', $request->string('stage_code'))
            ))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn (Builder $q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('company_id'), fn (Builder $q) => $q->whereHas(
                'company', fn (Builder $c) => $c->where('uuid', $request->string('company_id'))
            ))
            ->when($request->filled('owner_id'), fn (Builder $q) => $q->whereHas(
                'owner', fn (Builder $o) => $o->where('uuid', $request->string('owner_id'))
            ))
            ->when($request->filled('agent_id'), fn (Builder $q) => $q->whereHas(
                'referralAgent', fn (Builder $a) => $a->where('uuid', $request->string('agent_id'))
            ))
            ->when($request->filled('source_code'), fn (Builder $q) => $q->whereHas(
                'leadSource', fn (Builder $s) => $s->where('code', $request->string('source_code'))
            ))
            // Execution filters: the questions the dashboard and the AI both ask.
            ->when($request->boolean('without_next_action'), fn (Builder $q) => $q->withoutNextAction())
            ->when($request->boolean('follow_up_due'), fn (Builder $q) => $q->followUpDueBy(now()->endOfDay()))
            ->when($request->boolean('awaiting_quotation_response'), fn (Builder $q) => $q->awaitingQuotationResponse())
            ->when($request->filled('inactive_days'), fn (Builder $q) => $q->inactiveSince(
                now()->subDays($request->integer('inactive_days'))
            ))
            ->with(['company:id,uuid,name', 'stage', 'owner:id,uuid,name', 'referralAgent:id,uuid,name', 'leadSource'])
            ->withCount('openTasks')
            ->orderBy(
                $request->string('sort_by', 'updated_at')->toString(),
                $request->string('sort_dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc',
            )
            ->paginate($request->integer('per_page', 25));

        return OpportunityResource::collection($opportunities);
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $this->authorize('create', Opportunity::class);

        $data = $this->resolveReferences($request->validated());

        // A referral agent submits leads under their own name only. Without this
        // they could credit a lead to a colleague, or to nobody, and then lose
        // sight of it entirely because their own visibility is referral-based.
        $user = $request->user();

        if (! $user->canDo(PermissionCode::OpportunityViewAll) && $user->canDo(PermissionCode::OpportunityViewOwnReferrals)) {
            $agentId = $user->agentProfile?->id;

            abort_if($agentId === null, 403, 'Your account is not linked to an agent profile.');

            $data['referral_agent_id'] = $agentId;
        }

        $opportunity = $this->opportunities->create($data);

        return (new OpportunityResource($opportunity))->response()->setStatusCode(201);
    }

    public function show(Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('view', $opportunity);

        return new OpportunityResource($opportunity->load([
            'company', 'primaryContact', 'stage', 'owner', 'referralAgent', 'leadSource',
        ])->loadCount('openTasks'));
    }

    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('update', $opportunity);

        $updated = $this->opportunities->update($opportunity, $this->resolveReferences($request->validated()));

        return new OpportunityResource($updated);
    }

    public function destroy(Opportunity $opportunity): JsonResponse
    {
        $this->authorize('delete', $opportunity);

        $opportunity->delete();

        return response()->json(null, 204);
    }

    public function changeStage(ChangeStageRequest $request, Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('changeStage', $opportunity);

        $updated = $this->stageTransitions->change($opportunity, new StageChangeData(
            toStageId: $request->integer('stage_id'),
            note: $request->input('note'),
            lossReason: $request->input('loss_reason'),
            lossNote: $request->input('loss_note'),
            finalValue: $request->filled('final_value') ? (float) $request->input('final_value') : null,
            nextAction: $request->input('next_action'),
            nextFollowUpAt: $request->date('next_follow_up_at'),
        ));

        return new OpportunityResource($updated);
    }

    public function setNextAction(SetNextActionRequest $request, Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('update', $opportunity);

        $updated = $this->opportunities->setNextAction(
            $opportunity,
            $request->input('next_action'),
            $request->date('next_follow_up_at'),
            $request->input('no_action_reason'),
        );

        return new OpportunityResource($updated->load(['company', 'stage', 'owner']));
    }

    public function addNote(AddNoteRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $this->opportunities->addNote(
            $opportunity,
            $request->string('body')->toString(),
            $request->boolean('is_internal'),
            $request->activityType(),
        );

        return response()->json(['message' => 'Note recorded.'], 201);
    }

    public function assignOwner(Request $request, Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('assignOwner', $opportunity);

        $validated = $request->validate([
            'owner_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists(User::class, 'uuid')
                ->where('organization_id', \App\Support\OrganizationContext::id())],
        ]);

        $owner = User::whereUuid($validated['owner_id'])->firstOrFail();

        return new OpportunityResource($this->opportunities->reassignOwner($opportunity, $owner));
    }

    public function assignAgent(Request $request, Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('assignAgent', $opportunity);

        $validated = $request->validate([
            'agent_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists(Agent::class, 'uuid')],
        ]);

        $agent = $validated['agent_id'] === null
            ? null
            : Agent::whereUuid($validated['agent_id'])->firstOrFail();

        return new OpportunityResource($this->opportunities->reassignAgent($opportunity, $agent));
    }

    public function timeline(Request $request, Opportunity $opportunity): AnonymousResourceCollection
    {
        $this->authorize('view', $opportunity);

        $activities = $opportunity->activities()
            ->visibleTo($request->user())
            ->with('actor:id,uuid,name')
            ->paginate($request->integer('per_page', 50));

        return ActivityResource::collection($activities);
    }

    public function stageHistory(Opportunity $opportunity): AnonymousResourceCollection
    {
        $this->authorize('view', $opportunity);

        return StageHistoryResource::collection(
            $opportunity->stageHistory()->with(['fromStage', 'toStage', 'changedBy:id,uuid,name'])->get()
        );
    }

    /**
     * Visibility is decided once here, so no filter combination can widen it.
     */
    private function visibleQuery(User $user): Builder
    {
        $query = Opportunity::query();

        if ($user->canDo(PermissionCode::OpportunityViewAll)) {
            return $query;
        }

        if ($user->canDo(PermissionCode::OpportunityViewOwnReferrals)) {
            $agentId = $user->agentProfile?->id;

            return $agentId === null
                ? $query->whereRaw('1 = 0')
                : $query->forReferralAgent($agentId);
        }

        return $query->where('owner_user_id', $user->id);
    }

    /**
     * The API speaks UUIDs and codes; the database speaks integer keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveReferences(array $data): array
    {
        if (array_key_exists('company_id', $data)) {
            $data['company_id'] = Company::whereUuid($data['company_id'])->value('id');
        }

        if (array_key_exists('primary_contact_id', $data)) {
            $data['primary_contact_id'] = $data['primary_contact_id'] === null
                ? null
                : Contact::whereUuid($data['primary_contact_id'])->value('id');
        }

        if (array_key_exists('owner_id', $data)) {
            $data['owner_user_id'] = $data['owner_id'] === null
                ? null
                : User::whereUuid($data['owner_id'])->value('id');
            unset($data['owner_id']);
        }

        if (array_key_exists('referral_agent_id', $data)) {
            $data['referral_agent_id'] = $data['referral_agent_id'] === null
                ? null
                : Agent::whereUuid($data['referral_agent_id'])->value('id');
        }

        if (array_key_exists('lead_source_code', $data)) {
            $data['lead_source_id'] = $data['lead_source_code'] === null
                ? null
                : LeadSource::where('code', $data['lead_source_code'])->value('id');
            unset($data['lead_source_code']);
        }

        return $data;
    }
}
