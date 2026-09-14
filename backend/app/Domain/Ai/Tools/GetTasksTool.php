<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskVisibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Covers both get_tasks and get_overdue_tasks from the specification. */
class GetTasksTool extends ReadTool
{
    public function __construct(private readonly TaskVisibility $visibility) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_tasks',
            description: 'Tasks and follow-ups the user can see. Use bucket=overdue for what is late, '
                .'due_today for today\'s work, upcoming for the days ahead, unassigned for work nobody owns.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'bucket' => [
                        'type' => 'string',
                        'enum' => ['overdue', 'due_today', 'upcoming', 'unassigned', 'open', 'all'],
                        'description' => 'Default open.',
                    ],
                    'assignee_name' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'Default 25, capped at 100.'],
                ],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::TaskViewOwn->value;
    }

    public function execute(User $user, array $arguments): array
    {
        $bucket = $arguments['bucket'] ?? 'open';

        $query = $this->visibility->scope(Task::query(), $user);

        $query = match ($bucket) {
            'overdue' => $query->overdue(),
            'due_today' => $query->dueToday(),
            'upcoming' => $query->upcoming(),
            'unassigned' => $query->unassigned(),
            'all' => $query,
            default => $query->open(),
        };

        $tasks = $query
            ->when(filled($arguments['assignee_name'] ?? null), fn (Builder $q) => $q->whereHas(
                'assignee', fn (Builder $a) => $a->where('name', 'like', '%'.$arguments['assignee_name'].'%')
            ))
            ->with(['assignee:id,name', 'subject'])
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->limit(min((int) ($arguments['limit'] ?? 25), 100))
            ->get();

        return [
            'bucket' => $bucket,
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $t) => [
                'reference' => $t->uuid,
                'title' => $t->title,
                'status' => $t->status->value,
                'priority' => $t->priority->value,
                'due_at' => $t->due_at?->toDateTimeString(),
                'is_overdue' => $t->isOverdue(),
                'assignee' => $t->assignee?->name,
                'attached_to' => $t->subject_type === null ? null : [
                    'type' => $t->subject_type,
                    'name' => $t->subject?->title ?? $t->subject?->name,
                ],
            ])->all(),
        ];
    }
}
