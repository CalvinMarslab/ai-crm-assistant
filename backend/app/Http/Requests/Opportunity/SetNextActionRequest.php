<?php

namespace App\Http\Requests\Opportunity;

use Illuminate\Foundation\Http\FormRequest;

class SetNextActionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_follow_up_at' => ['nullable', 'date'],
            // Architecture rule 7: clearing the next action demands a reason.
            'no_action_reason' => ['nullable', 'string', 'max:255', 'required_without:next_action'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'no_action_reason.required_without' => 'Provide a next action, or state why there is none.',
        ];
    }
}
