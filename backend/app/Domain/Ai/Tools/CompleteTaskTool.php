<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CompleteTaskTool extends WriteTool
{
    public function __construct(private readonly TaskService $tasks) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'complete_task',
            description: 'Mark one existing task as completed. The user confirms before anything is saved.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'description' => 'Task UUID from get_tasks.'],
                ],
                'required' => ['reference'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::TaskManage->value;
    }

    public function summarise(User $user, array $arguments): string
    {
        $task = $this->task($arguments);
        Gate::forUser($user)->authorize('update', $task);

        return 'Complete task "'.$task->title.'".';
    }

    public function execute(User $user, array $arguments): array
    {
        $task = $this->task($arguments);
        Gate::forUser($user)->authorize('update', $task);
        $task = $this->tasks->complete($task);

        return ['completed' => true, 'reference' => $task->uuid, 'title' => $task->title];
    }

    private function task(array $arguments): Task
    {
        return Task::query()->where('uuid', $arguments['reference'] ?? '')->firstOrFail();
    }
}
