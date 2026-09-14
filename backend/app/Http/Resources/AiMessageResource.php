<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'role' => $this->role,
            'content' => $this->content,
            // Which tools ran, so the user can see what the answer was based
            // on rather than taking the prose on trust.
            'tools_used' => $this->tool_calls === null
                ? []
                : array_values(array_map(fn (array $call) => $call['name'] ?? 'unknown', $this->tool_calls)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
