<?php

namespace App\Http\Requests\Agent;

class UpdateAgentRequest extends StoreAgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);
    }
}
