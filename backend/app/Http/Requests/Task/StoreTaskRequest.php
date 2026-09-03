<?php

namespace App\Http\Requests\Task;

use App\Domain\Opportunity\Enums\Priority;
use App\Domain\Task\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigned_user_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')],
            'subject_type' => ['nullable', 'string', Rule::in(['opportunity', 'company', 'contact'])],
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
