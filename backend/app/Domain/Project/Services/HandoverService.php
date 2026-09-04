<?php

namespace App\Domain\Project\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Project\Enums\HandoverItemStatus;
use App\Domain\Project\Models\ProjectHandoverItem;
use Illuminate\Support\Facades\DB;

class HandoverService
{
    public function __construct(
        private readonly ActivityRecorder $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(ProjectHandoverItem $item, array $attributes): ProjectHandoverItem
    {
        return DB::transaction(function () use ($item, $attributes) {
            $previous = $item->status;

            if (isset($attributes['status'])) {
                $status = $attributes['status'] instanceof HandoverItemStatus
                    ? $attributes['status']
                    : HandoverItemStatus::from($attributes['status']);

                $attributes['completed_at'] = $status->isSettled() ? ($item->completed_at ?? now()) : null;
            }

            $item->update($attributes);

            if ($item->status !== $previous) {
                $this->activities->record(
                    type: ActivityType::HandoverItemUpdated,
                    subject: $item->project,
                    title: "Handover: {$item->title} — {$item->status->label()}",
                    metadata: ['from' => $previous->value, 'to' => $item->status->value],
                );
            }

            return $item->fresh('assignee');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(\App\Domain\Project\Models\Project $project, array $attributes): ProjectHandoverItem
    {
        $attributes['project_id'] = $project->id;
        $attributes['sequence'] ??= ((int) $project->handoverItems()->max('sequence')) + 10;

        return ProjectHandoverItem::create($attributes);
    }
}
