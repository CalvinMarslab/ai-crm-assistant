<?php

namespace App\Http\Requests\Task;

class UpdateTaskRequest extends StoreTaskRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
        ]);
    }
}
