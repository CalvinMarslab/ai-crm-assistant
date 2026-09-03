<?php

namespace App\Domain\Task\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Notification\Services\Notifier;
use App\Domain\Task\Enums\TaskStatus;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private readonly ActivityRecorder $activities,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Task
    {
        return DB::transaction(function () use ($attributes) {
            $attributes['created_by_user_id'] ??= Auth::id();

            $task = Task::create($attributes);

            $this->recordOnSubjectTimeline($task, ActivityType::TaskCreated, "Task created: {$task->title}");
            $this->notifyAssignee($task, 'task.assigned', 'New task assigned', $task->title);

            return $task->load(['assignee', 'creator', 'subject']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Task $task, array $attributes): Task
    {
        return DB::transaction(function () use ($task, $attributes) {
            $previousAssignee = $task->assigned_user_id;

            // Completion has its own path so completed_at is never left behind.
            if (isset($attributes['status'])) {
                $status = $attributes['status'] instanceof TaskStatus
                    ? $attributes['status']
                    : TaskStatus::from($attributes['status']);

                $attributes['completed_at'] = $status->isClosed() ? ($task->completed_at ?? now()) : null;
            }

            $task->update($attributes);

            if ($task->assigned_user_id !== $previousAssignee) {
                $this->notifyAssignee($task, 'task.assigned', 'Task assigned to you', $task->title);
            }

            return $task->fresh(['assignee', 'creator', 'subject']);
        });
    }

    public function complete(Task $task): Task
    {
        return DB::transaction(function () use ($task) {
            $task->update([
                'status' => TaskStatus::Done,
                'completed_at' => now(),
            ]);

            $this->recordOnSubjectTimeline($task, ActivityType::TaskCompleted, "Task completed: {$task->title}");

            return $task->fresh(['assignee', 'subject']);
        });
    }

    public function reopen(Task $task): Task
    {
        return DB::transaction(function () use ($task) {
            $task->update([
                'status' => TaskStatus::ToDo,
                'completed_at' => null,
            ]);

            $this->recordOnSubjectTimeline($task, ActivityType::TaskReopened, "Task reopened: {$task->title}");

            return $task->fresh(['assignee', 'subject']);
        });
    }

    /**
     * Tasks appear on the timeline of whatever they are attached to, so an
     * opportunity's history reads as one story (PRD section 9).
     */
    private function recordOnSubjectTimeline(Task $task, ActivityType $type, string $title): void
    {
        $subject = $task->subject;

        if ($subject === null) {
            return;
        }

        $this->activities->record(
            type: $type,
            subject: $subject,
            title: $title,
            body: $task->description,
            metadata: ['task_uuid' => $task->uuid, 'due_at' => $task->due_at?->format(DATE_ATOM)],
            isInternal: $task->is_internal,
        );
    }

    private function notifyAssignee(Task $task, string $type, string $title, string $body): void
    {
        if ($task->assigned_user_id === null || $task->assigned_user_id === Auth::id()) {
            return;
        }

        $assignee = User::find($task->assigned_user_id);

        if ($assignee !== null) {
            $this->notifier->notify($assignee, $type, $title, $body, [
                'task_uuid' => $task->uuid,
                'due_at' => $task->due_at?->format(DATE_ATOM),
            ]);
        }
    }
}
