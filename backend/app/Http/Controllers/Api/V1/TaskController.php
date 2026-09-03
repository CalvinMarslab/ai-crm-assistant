<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    /** API subject aliases to their models, matching the enforced morph map. */
    private const SUBJECTS = [
        'opportunity' => Opportunity::class,
        'company' => Company::class,
        'contact' => Contact::class,
    ];

    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);

        $tasks = $this->visibleQuery($request->user())
            ->when($request->filled('search'), fn (Builder $q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn (Builder $q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('assignee_id'), fn (Builder $q) => $q->whereHas(
                'assignee', fn (Builder $a) => $a->where('uuid', $request->string('assignee_id'))
            ))
            ->when($request->boolean('open'), fn (Builder $q) => $q->open())
            ->when($request->boolean('overdue'), fn (Builder $q) => $q->overdue())
            ->when($request->boolean('due_today'), fn (Builder $q) => $q->dueToday())
            ->when($request->boolean('upcoming'), fn (Builder $q) => $q->upcoming($request->integer('upcoming_days', 7)))
            ->when($request->boolean('unassigned'), fn (Builder $q) => $q->unassigned())
            ->when($request->filled('subject_type') && $request->filled('subject_id'), function (Builder $q) use ($request) {
                $subject = $this->resolveSubject($request->string('subject_type')->toString(), $request->string('subject_id')->toString());

                return $subject === null
                    ? $q->whereRaw('1 = 0')
                    : $q->where('subject_type', $request->string('subject_type'))->where('subject_id', $subject);
            })
            ->with(['assignee:id,uuid,name', 'creator:id,uuid,name', 'subject'])
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->paginate($request->integer('per_page', 25));

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $task = $this->tasks->create($this->resolveReferences($request->validated()));

        return (new TaskResource($task))->response()->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task->load(['assignee', 'creator', 'subject']));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $this->authorize('update', $task);

        return new TaskResource($this->tasks->update($task, $this->resolveReferences($request->validated())));
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->json(null, 204);
    }

    public function complete(Task $task): TaskResource
    {
        $this->authorize('update', $task);

        return new TaskResource($this->tasks->complete($task));
    }

    public function reopen(Task $task): TaskResource
    {
        $this->authorize('update', $task);

        return new TaskResource($this->tasks->reopen($task));
    }

    private function visibleQuery(User $user): Builder
    {
        return $user->canDo(PermissionCode::TaskViewAll)
            ? Task::query()
            : Task::query()->where(fn (Builder $q) => $q
                ->where('assigned_user_id', $user->id)
                ->orWhere('created_by_user_id', $user->id));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveReferences(array $data): array
    {
        if (array_key_exists('assigned_user_id', $data)) {
            $data['assigned_user_id'] = $data['assigned_user_id'] === null
                ? null
                : User::whereUuid($data['assigned_user_id'])->value('id');
        }

        if (! empty($data['subject_type']) && ! empty($data['subject_id'])) {
            $data['subject_id'] = $this->resolveSubject($data['subject_type'], $data['subject_id']);
        }

        return $data;
    }

    private function resolveSubject(string $type, string $uuid): ?int
    {
        $model = self::SUBJECTS[$type] ?? null;

        return $model === null ? null : $model::whereUuid($uuid)->value('id');
    }
}
