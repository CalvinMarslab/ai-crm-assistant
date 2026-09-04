<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $showFinancials = Gate::allows('viewFinancials', $this->resource);

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // What a referral agent is shown in place of the internal status.
            'agent_facing_status' => $this->status->agentFacingStatus(),
            'is_blocked' => $this->status->isBlocked(),

            'summary' => $this->summary,
            'requirements' => $this->when($showFinancials, $this->requirements),

            'company' => new CompanySummaryResource($this->whenLoaded('company')),
            'primary_contact' => new ContactResource($this->whenLoaded('primaryContact')),
            'manager' => new UserSummaryResource($this->whenLoaded('manager')),
            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity === null ? null : [
                'id' => $this->opportunity->uuid,
                'title' => $this->opportunity->title,
            ]),

            'contract_value' => $this->when($showFinancials, fn () => $this->contract_value === null ? null : (float) $this->contract_value),
            'quotation_reference' => $this->when($showFinancials, $this->quotation_reference),

            'start_date' => $this->start_date?->toDateString(),
            'target_end_date' => $this->target_end_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'handed_over_at' => $this->handed_over_at?->toIso8601String(),

            'handover_items' => HandoverItemResource::collection($this->whenLoaded('handoverItems')),
            'handover_complete' => $this->when(
                $this->relationLoaded('handoverItems'),
                fn () => $this->handoverComplete(),
            ),
            'open_tasks_count' => $this->whenCounted('openTasks'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
