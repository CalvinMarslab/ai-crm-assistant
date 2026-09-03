<?php

namespace App\Http\Resources;

use App\Domain\Identity\Enums\PermissionCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->activity_type->value,
            'type_label' => $this->activity_type->label(),
            'title' => $this->title,
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            // Metadata carries raw before/after values for changed fields, which
            // can include commercial figures. Only users cleared for financials
            // receive it; everyone else still gets the human-readable title.
            'metadata' => $this->when(
                $request->user()?->canDo(PermissionCode::OpportunityViewFinancials) ?? false,
                $this->metadata,
            ),
            'actor' => new UserSummaryResource($this->whenLoaded('actor')),
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->whenLoaded('subject', fn () => $this->subject?->uuid),
            ],
            'company' => new CompanySummaryResource($this->whenLoaded('company')),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
