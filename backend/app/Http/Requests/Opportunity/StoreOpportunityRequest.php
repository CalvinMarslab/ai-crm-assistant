<?php

namespace App\Http\Requests\Opportunity;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Enums\Priority;
use App\Domain\Opportunity\Enums\QuotationStatus;
use App\Domain\Opportunity\Models\LeadSource;
use App\Domain\Pipeline\Models\Pipeline;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
{
    /** Authorization runs before validation, so a denied caller gets 403, not 422. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Opportunity::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'uuid', Rule::exists(Company::class, 'uuid')],
            'primary_contact_id' => ['nullable', 'uuid', Rule::exists(Contact::class, 'uuid')],
            'pipeline_id' => ['nullable', 'integer', Rule::exists(Pipeline::class, 'id')],
            'stage_id' => ['nullable', 'integer', Rule::exists(PipelineStage::class, 'id')],
            'owner_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')
                ->where('organization_id', OrganizationContext::id())],
            'referral_agent_id' => ['nullable', 'uuid', Rule::exists(Agent::class, 'uuid')],
            'lead_source_code' => ['nullable', 'string', Rule::exists(LeadSource::class, 'code')],

            'summary' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:20000'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'quotation_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'quotation_status' => ['nullable', Rule::enum(QuotationStatus::class)],
            'quotation_sent_at' => ['nullable', 'date'],
            'probability' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'expected_close_date' => ['nullable', 'date'],
            'last_contact_at' => ['nullable', 'date'],
            'next_follow_up_at' => ['nullable', 'date'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'no_action_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.required' => 'An opportunity must belong to a company.',
        ];
    }
}
