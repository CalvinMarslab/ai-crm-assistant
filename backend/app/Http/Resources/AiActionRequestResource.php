<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiActionRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'action' => $this->action_name,
            'summary' => $this->summary,
            'payload' => $this->action_payload,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_actionable' => $this->isActionable(),
            'has_expired' => $this->hasExpired(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'result' => $this->execution_result,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
