<?php

namespace App\Http\Requests\Company;

class UpdateCompanyRequest extends StoreCompanyRequest
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
