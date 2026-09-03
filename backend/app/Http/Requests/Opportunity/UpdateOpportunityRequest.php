<?php

namespace App\Http\Requests\Opportunity;

class UpdateOpportunityRequest extends StoreOpportunityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'company_id' => ['sometimes', 'required', 'uuid', \Illuminate\Validation\Rule::exists(\App\Domain\Company\Models\Company::class, 'uuid')],
        ]);

        // Stage moves go through the dedicated endpoint so history is never skipped.
        unset($rules['stage_id'], $rules['pipeline_id']);

        return $rules;
    }
}
