<?php

namespace App\Http\Requests\Contact;

class UpdateContactRequest extends StoreContactRequest
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
