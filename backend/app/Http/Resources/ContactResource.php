<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_primary' => $this->is_primary,
            'notes' => $this->notes,
            'company' => new CompanySummaryResource($this->whenLoaded('company')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
