<?php

namespace App\Http\Resources;

use App\Domain\Opportunity\Services\OpportunityHygieneService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class OpportunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Referral agents get status without commercial detail.
        $showFinancials = Gate::allows('viewFinancials', $this->resource);

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'summary' => $this->summary,
            'requirements' => $this->when($showFinancials, $this->requirements),

            'company' => new CompanySummaryResource($this->whenLoaded('company')),
            'primary_contact' => new ContactResource($this->whenLoaded('primaryContact')),
            'owner' => new UserSummaryResource($this->whenLoaded('owner')),
            'referral_agent' => $this->whenLoaded('referralAgent', fn () => $this->referralAgent ? [
                'id' => $this->referralAgent->uuid,
                'name' => $this->referralAgent->name,
            ] : null),
            'lead_source' => $this->whenLoaded('leadSource', fn () => $this->leadSource ? [
                'code' => $this->leadSource->code,
                'name' => $this->leadSource->name,
            ] : null),

            'stage' => $this->whenLoaded('stage', fn () => [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
                'code' => $this->stage->code,
                'stage_type' => $this->stage->stage_type->value,
                'sequence' => $this->stage->sequence,
                // What a referral agent sees instead of the internal stage name.
                'agent_facing_status' => $this->stage->agent_facing_status,
            ]),
            'pipeline_id' => $this->pipeline_id,
            'status' => $this->status,
            'priority' => $this->priority?->value,
            'probability' => $this->probability === null ? null : (float) $this->probability,

            'estimated_value' => $this->when($showFinancials, fn () => $this->estimated_value === null ? null : (float) $this->estimated_value),
            'quotation_amount' => $this->when($showFinancials, fn () => $this->quotation_amount === null ? null : (float) $this->quotation_amount),
            'final_value' => $this->when($showFinancials, fn () => $this->final_value === null ? null : (float) $this->final_value),
            'quotation_status' => $this->quotation_status?->value,
            'quotation_sent_at' => $this->quotation_sent_at?->toIso8601String(),

            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'last_contact_at' => $this->last_contact_at?->toIso8601String(),
            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'next_action' => $this->next_action,
            'no_action_reason' => $this->no_action_reason,
            'has_next_action' => $this->hasNextAction(),

            'loss_reason' => $this->loss_reason,
            'loss_note' => $this->when($showFinancials, $this->loss_note),
            'won_at' => $this->won_at?->toIso8601String(),
            'lost_at' => $this->lost_at?->toIso8601String(),

            // Present once a won deal has been converted (Phase 2).
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->uuid,
                'name' => $this->project->name,
                'status' => $this->project->status->value,
                'status_label' => $this->project->status->label(),
            ]),

            'open_tasks_count' => $this->whenCounted('openTasks'),
            'warnings' => $this->when(
                $request->boolean('with_warnings', true),
                fn () => app(OpportunityHygieneService::class)->warningsFor($this->resource),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
