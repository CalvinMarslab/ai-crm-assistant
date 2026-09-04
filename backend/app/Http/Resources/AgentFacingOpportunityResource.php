<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a referral agent sees for a lead they introduced (CRM_WORKFLOW.md
 * section 7). Deliberately narrow: a simplified status instead of the internal
 * stage, no commercial figures, no internal notes, no owner detail.
 *
 * Built as its own resource rather than as flags on OpportunityResource so the
 * agent-facing shape is explicit and cannot widen by accident.
 */
class AgentFacingOpportunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->whenLoaded('project') instanceof \Illuminate\Http\Resources\MissingValue
            ? null
            : $this->project;

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'company' => $this->whenLoaded('company', fn () => $this->company?->name),

            // Project status supersedes the sales stage once delivery starts.
            'status' => $project !== null
                ? $project->status->agentFacingStatus()
                : ($this->stage?->agent_facing_status ?? 'New'),
            'is_closed' => in_array($this->status, ['won', 'lost'], true),
            'outcome' => match ($this->status) {
                'won' => 'Won',
                'lost' => 'Lost',
                default => null,
            },

            'submitted_at' => $this->created_at?->toIso8601String(),
            'last_update_at' => $this->updated_at?->toIso8601String(),
            'expected_close_date' => $this->expected_close_date?->toDateString(),
        ];
    }
}
