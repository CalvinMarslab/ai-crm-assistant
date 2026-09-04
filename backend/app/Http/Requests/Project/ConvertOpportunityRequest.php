<?php

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertOpportunityRequest extends FormRequest
{
    /** Authorization runs before validation, so a denied caller gets 403, not 422. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'project_manager_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')
                ->where('organization_id', OrganizationContext::id())],
            'summary' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:20000'],
            'start_date' => ['nullable', 'date'],
            'target_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
