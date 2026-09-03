<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'due_at' => $this->due_at?->toIso8601String(),
            'reminder_at' => $this->reminder_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'source' => $this->source,
            'assignee' => new UserSummaryResource($this->whenLoaded('assignee')),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject->uuid,
                'label' => $this->subject->title ?? $this->subject->name ?? null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
