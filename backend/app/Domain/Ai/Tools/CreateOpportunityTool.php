<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\OpportunityService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CreateOpportunityTool extends WriteTool
{
    public function __construct(private readonly OpportunityService $opportunities) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'create_opportunity',
            description: 'Create a new sales case/opportunity for an existing company. The user confirms before it is saved.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'company_reference' => ['type' => 'string', 'description' => 'Company UUID returned by search_companies.'],
                    'summary' => ['type' => 'string'],
                    'estimated_value' => ['type' => 'number'],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                    'next_action' => ['type' => 'string'],
                    'next_follow_up_at' => ['type' => 'string'],
                ],
                'required' => ['title', 'company_reference'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityCreate->value;
    }

    public function summarise(User $user, array $arguments): string
    {
        Gate::forUser($user)->authorize('create', Opportunity::class);
        $company = $this->company($arguments);

        return 'Create opportunity "'.($arguments['title'] ?? '').'" for '.$company->name.'.';
    }

    public function execute(User $user, array $arguments): array
    {
        Gate::forUser($user)->authorize('create', Opportunity::class);
        $company = $this->company($arguments);
        Auth::setUser($user);
        $attributes = [
            'title' => $arguments['title'],
            'company_id' => $company->id,
            'summary' => $arguments['summary'] ?? null,
            'estimated_value' => $arguments['estimated_value'] ?? null,
            'priority' => $arguments['priority'] ?? 'normal',
            'next_action' => $arguments['next_action'] ?? null,
            'next_follow_up_at' => $arguments['next_follow_up_at'] ?? null,
        ];

        if (! $user->canDo(PermissionCode::OpportunityViewAll) && $user->canDo(PermissionCode::OpportunityViewOwnReferrals)) {
            abort_if($user->agentProfile === null, 403, 'Your account is not linked to an agent profile.');
            $attributes['referral_agent_id'] = $user->agentProfile->id;
        }

        $opportunity = $this->opportunities->create($attributes);

        return ['created' => true, 'reference' => $opportunity->uuid, 'title' => $opportunity->title];
    }

    private function company(array $arguments): Company
    {
        return Company::query()->where('uuid', $arguments['company_reference'] ?? '')->firstOrFail();
    }
}
