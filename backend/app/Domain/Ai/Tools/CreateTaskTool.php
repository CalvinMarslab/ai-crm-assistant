<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Task\Services\TaskService;
use App\Domain\Task\Services\TaskSubjectResolver;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CreateTaskTool extends WriteTool
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskSubjectResolver $subjects,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'create_task',
            description: 'Create a task or follow-up. Attach it to an opportunity or project by passing '
                .'its reference. The user confirms before anything is saved.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'due_at' => ['type' => 'string', 'description' => 'Date or datetime, e.g. 2026-09-12.'],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                    'subject_type' => ['type' => 'string', 'enum' => ['opportunity', 'project', 'company', 'contact']],
                    'subject_reference' => ['type' => 'string', 'description' => 'Required when subject_type is given.'],
                ],
                'required' => ['title'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::TaskManage->value;
    }

    public function summarise(User $user, array $arguments): string
    {
        $summary = 'Create task "'.($arguments['title'] ?? '').'"';

        if (filled($arguments['due_at'] ?? null)) {
            $summary .= ', due '.$arguments['due_at'];
        }

        if (filled($arguments['subject_reference'] ?? null)) {
            $subject = $this->subjects->resolve($arguments['subject_type'] ?? 'opportunity', $arguments['subject_reference']);
            $summary .= ', on '.($subject->title ?? $subject->name ?? 'the selected record');
        }

        return $summary.'.';
    }

    public function execute(User $user, array $arguments): array
    {
        $attributes = [
            'title' => $arguments['title'],
            'description' => $arguments['description'] ?? null,
            'due_at' => filled($arguments['due_at'] ?? null) ? $arguments['due_at'] : null,
            'priority' => $arguments['priority'] ?? 'normal',
            'created_by_user_id' => $user->id,
            'assigned_user_id' => $user->id,
            'source' => 'ai',
        ];

        if (filled($arguments['subject_reference'] ?? null)) {
            // The same resolver a human request goes through, so the subject is
            // authorized identically.
            $subject = $this->subjects->resolveAuthorized(
                $arguments['subject_type'] ?? 'opportunity',
                $arguments['subject_reference'],
            );

            $attributes['subject_type'] = $subject->getMorphClass();
            $attributes['subject_id'] = $subject->getKey();
        }

        // The domain service reads the acting user for authorship and audit.
        Auth::setUser($user);

        $task = $this->tasks->create($attributes);

        return ['created' => true, 'reference' => $task->uuid, 'title' => $task->title];
    }
}
