<?php

namespace App\Http\Requests\Service;

use App\Domain\Service\Models\ServiceSubscription;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service ? $this->user()->can('update', $service) : $this->user()->can('create', ServiceSubscription::class);
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'uuid')->where('organization_id', OrganizationContext::id())->whereNull('deleted_at')],
            'cycle_id' => [$this->route('service') ? 'required' : 'prohibited', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['maintenance', 'server'])],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'internal_email' => ['required', 'email', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'currency' => ['required_if:status,active', 'nullable', Rule::in(['MYR', 'SGD', 'USD'])],
            'renewal_months' => ['required_if:status,active', 'nullable', 'integer', 'between:1,120'],
            'expires_on' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['active', 'paused', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
