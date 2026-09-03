<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from_stage' => $this->whenLoaded('fromStage', fn () => $this->fromStage ? [
                'name' => $this->fromStage->name,
                'code' => $this->fromStage->code,
            ] : null),
            'to_stage' => $this->whenLoaded('toStage', fn () => [
                'name' => $this->toStage->name,
                'code' => $this->toStage->code,
                'agent_facing_status' => $this->toStage->agent_facing_status,
            ]),
            'changed_by' => new UserSummaryResource($this->whenLoaded('changedBy')),
            'changed_at' => $this->changed_at?->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
