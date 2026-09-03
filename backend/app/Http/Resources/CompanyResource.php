<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'registration_no' => $this->registration_no,
            'industry' => $this->industry,
            'website' => $this->website,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'notes' => $this->notes,
            'contacts_count' => $this->whenCounted('contacts'),
            'opportunities_count' => $this->whenCounted('opportunities'),
            'open_opportunities_value' => $this->when(isset($this->open_opportunities_value), fn () => round((float) $this->open_opportunities_value, 2)),
            'contacts' => ContactResource::collection($this->whenLoaded('contacts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
