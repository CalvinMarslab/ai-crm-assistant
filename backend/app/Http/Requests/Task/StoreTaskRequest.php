<?php

namespace App\Http\Requests\Task;

use App\Domain\Opportunity\Enums\Priority;
use App\Domain\Task\Enums\TaskStatus;
use App\Domain\Task\Services\TaskSubjectResolver;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    /** Authorization runs before validation, so a denied caller gets 403, not 422. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Task::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigned_user_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')
                ->where('organization_id', OrganizationContext::id())],
            // Both halves travel together; one without the other cannot
            // identify a record, on create or on re-bind.
            'subject_type' => ['nullable', 'string', 'required_with:subject_id', Rule::in(TaskSubjectResolver::types())],
            'subject_id' => ['nullable', 'uuid', 'required_with:subject_type'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'due_at' => ['nullable', 'date'],
            'reminder_at' => ['nullable', 'date'],
            'source' => ['sometimes', Rule::in(['manual', 'follow_up', 'system'])],
            'is_internal' => ['sometimes', 'boolean'],
        ];
    }
}
