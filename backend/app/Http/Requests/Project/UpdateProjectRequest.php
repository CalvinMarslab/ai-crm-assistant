<?php

namespace App\Http\Requests\Project;

use App\Domain\Company\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:20000'],
            'primary_contact_id' => ['nullable', 'uuid', Rule::exists(Contact::class, 'uuid')],
            'start_date' => ['nullable', 'date'],
            'target_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
