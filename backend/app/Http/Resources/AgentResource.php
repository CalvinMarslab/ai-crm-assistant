<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    /**
     * @param  array<string, mixed>|null  $stats
     */
    public function __construct($resource, private readonly ?array $stats = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'notes' => $this->notes,
            'joined_at' => $this->joined_at?->toDateString(),
            'has_portal_access' => $this->user_id !== null,
            'opportunities_count' => $this->whenCounted('opportunities'),
            'stats' => $this->when($this->stats !== null, $this->stats),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
