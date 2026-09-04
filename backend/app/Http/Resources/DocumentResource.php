<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'document_type' => $this->document_type,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'is_internal' => $this->is_internal,
            'uploader' => new UserSummaryResource($this->whenLoaded('uploader')),
            'subject' => ['type' => $this->subject_type],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
