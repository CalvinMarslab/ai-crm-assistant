<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Company\Models\Contact;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectHandoverItem;
use App\Domain\Project\Services\HandoverService;
use App\Domain\Project\Services\ProjectService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ConvertOpportunityRequest;
use App\Http\Requests\Project\UpdateHandoverItemRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\HandoverItemResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly HandoverService $handover,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $projects = $this->visibleQuery($request->user())
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', '%'.$request->string('search').'%'))))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('open'), fn (Builder $q) => $q->open())
            ->when($request->filled('manager_id'), fn (Builder $q) => $q->whereHas(
                'manager', fn (Builder $m) => $m->where('uuid', $request->string('manager_id'))
            ))
            ->when($request->boolean('unassigned'), fn (Builder $q) => $q->whereNull('project_manager_user_id'))
            ->with(['company:id,uuid,name', 'manager:id,uuid,name', 'opportunity:id,uuid,title'])
            ->withCount('openTasks')
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 25));

        return ProjectResource::collection($projects);
    }

    /** PRD section 14: convert a won opportunity into a project. */
    public function convert(ConvertOpportunityRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);

        $data = $request->validated();

        if (! empty($data['project_manager_id'])) {
            $data['project_manager_user_id'] = User::whereUuid($data['project_manager_id'])->value('id');
        }

        unset($data['project_manager_id']);

        $project = $this->projects->convertFromOpportunity($opportunity, $data);

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource($project->load([
            'company', 'primaryContact', 'manager', 'opportunity',
            'handoverItems.assignee',
        ])->loadCount('openTasks'));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $data = $request->validated();

        if (array_key_exists('primary_contact_id', $data)) {
            $data['primary_contact_id'] = $data['primary_contact_id'] === null
                ? null
                : Contact::whereUuid($data['primary_contact_id'])->value('id');
        }

        return new ProjectResource($this->projects->update($project, $data));
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(null, 204);
    }

    public function changeStatus(Request $request, Project $project): ProjectResource
    {
        $this->authorize('updateStatus', $project);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->projects->changeStatus(
            $project,
            ProjectStatus::from($validated['status']),
            $validated['note'] ?? null,
        );

        return new ProjectResource($updated->load(['company', 'manager']));
    }

    public function assignManager(Request $request, Project $project): ProjectResource
    {
        $this->authorize('assignManager', $project);

        $validated = $request->validate([
            'manager_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')
                ->where('organization_id', \App\Support\OrganizationContext::id())],
        ]);

        $manager = empty($validated['manager_id'])
            ? null
            : User::whereUuid($validated['manager_id'])->firstOrFail();

        return new ProjectResource($this->projects->assignManager($project, $manager)->load(['company', 'manager']));
    }

    public function addNote(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $this->projects->addNote($project, $validated['body'], (bool) ($validated['is_internal'] ?? false));

        return response()->json(['message' => 'Note recorded.'], 201);
    }

    public function handoverItems(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return HandoverItemResource::collection($project->handoverItems()->with('assignee')->get());
    }

    public function updateHandoverItem(
        UpdateHandoverItemRequest $request,
        Project $project,
        ProjectHandoverItem $item,
    ): HandoverItemResource {
        $this->authorize('manageHandover', $project);

        abort_if($item->project_id !== $project->id, 404);

        $data = $request->validated();

        if (array_key_exists('assigned_user_id', $data)) {
            $data['assigned_user_id'] = $data['assigned_user_id'] === null
                ? null
                : User::whereUuid($data['assigned_user_id'])->value('id');
        }

        return new HandoverItemResource($this->handover->updateItem($item, $data));
    }

    public function addHandoverItem(UpdateHandoverItemRequest $request, Project $project): JsonResponse
    {
        $this->authorize('manageHandover', $project);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ]);

        $item = $this->handover->addItem($project, $data);

        return (new HandoverItemResource($item))->response()->setStatusCode(201);
    }

    public function timeline(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        $activities = $project->activities()
            ->visibleTo($request->user())
            ->with('actor:id,uuid,name')
            ->paginate($request->integer('per_page', 50));

        return ActivityResource::collection($activities);
    }

    public function tasks(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return TaskResource::collection(
            $project->tasks()->with(['assignee:id,uuid,name'])->orderByRaw('due_at IS NULL, due_at ASC')->get()
        );
    }

    /**
     * The sales history behind the project, which the PM needs for delivery
     * (CRM_WORKFLOW.md section 6) without gaining access to the wider pipeline.
     */
    public function handoverBrief(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load(['company', 'primaryContact', 'opportunity.stageHistory.toStage', 'handoverItems.assignee']);

        return response()->json([
            'data' => [
                'project' => new ProjectResource($project),
                'opportunity_timeline' => $project->opportunity === null
                    ? []
                    : ActivityResource::collection(
                        $project->opportunity->activities()->visibleTo($request->user())->with('actor:id,uuid,name')->limit(100)->get()
                    ),
            ],
        ]);
    }

    private function visibleQuery(User $user): Builder
    {
        $query = Project::query();

        if ($user->canDo(PermissionCode::ProjectViewAll)) {
            return $query;
        }

        if ($user->canDo(PermissionCode::ProjectViewAssigned)) {
            return $query->forManager($user->id);
        }

        if ($user->canDo(PermissionCode::ProjectViewOwnReferrals)) {
            $agentId = $user->agentProfile?->id;

            return $agentId === null ? $query->whereRaw('1 = 0') : $query->forReferralAgent($agentId);
        }

        return $query->whereRaw('1 = 0');
    }
}
