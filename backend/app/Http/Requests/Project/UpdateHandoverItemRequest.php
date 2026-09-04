<?php

namespace App\Http\Requests\Project;

use App\Domain\Project\Enums\HandoverItemStatus;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHandoverItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(HandoverItemStatus::class)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_user_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')
                ->where('organization_id', OrganizationContext::id())],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
