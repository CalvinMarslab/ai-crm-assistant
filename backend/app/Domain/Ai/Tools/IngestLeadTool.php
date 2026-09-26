<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Services\LeadIntakeService;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class IngestLeadTool extends WriteTool
{
    public function __construct(private readonly LeadIntakeService $intake) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'ingest_lead',
            description: 'Intake a new lead atomically: reuse or create referral agent and client company, create the opportunity with requirements, and assign the acting user a proposal task. Requires confirmation.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'agent_name' => ['type' => 'string'], 'agent_email' => ['type' => 'string'], 'agent_phone' => ['type' => 'string'], 'agent_company' => ['type' => 'string'],
                    'company_name' => ['type' => 'string'], 'company_registration_no' => ['type' => 'string'], 'company_email' => ['type' => 'string'], 'company_phone' => ['type' => 'string'], 'company_industry' => ['type' => 'string'],
                    'contact_name' => ['type' => 'string'], 'contact_email' => ['type' => 'string'], 'contact_phone' => ['type' => 'string'], 'contact_job_title' => ['type' => 'string'],
                    'lead_title' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'requirements' => ['type' => 'string'],
                    'estimated_value' => ['type' => 'number'], 'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']], 'proposal_due_at' => ['type' => 'string'],
                ],
                'required' => ['company_name', 'lead_title', 'requirements'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityCreate->value;
    }

    public function summarise(User $user, array $arguments): string
    {
        $arguments = $this->validated($arguments);

        return 'Intake lead "'.($arguments['lead_title'] ?? '').'" for '.($arguments['company_name'] ?? '')
            .', record requirements, and assign you a proposal task.';
    }

    public function execute(User $user, array $arguments): array
    {
        return $this->intake->ingest($user, $this->validated($arguments));
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function validated(array $arguments): array
    {
        return Validator::make($arguments, [
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_email' => ['nullable', 'email', 'max:255'],
            'agent_phone' => ['nullable', 'string', 'max:50'],
            'agent_company' => ['nullable', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_registration_no' => ['nullable', 'string', 'max:100'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_industry' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_job_title' => ['nullable', 'string', 'max:255'],
            'lead_title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['required', 'string', 'max:20000'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'proposal_due_at' => ['nullable', 'date'],
        ])->validate();
    }
}
