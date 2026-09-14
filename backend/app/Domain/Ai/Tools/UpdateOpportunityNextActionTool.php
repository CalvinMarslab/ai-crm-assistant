<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\OpportunityService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateOpportunityNextActionTool extends WriteTool
{
    public function __construct(private readonly OpportunityService $opportunities) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'update_opportunity_next_action',
            description: 'Set what happens next on an opportunity, and when to follow up. '
                .'If there genuinely is no next step, give no_action_reason instead. '
                .'The user confirms before anything is saved.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string'],
                    'next_action' => ['type' => 'string'],
                    'next_follow_up_at' => ['type' => 'string', 'description' => 'Date, e.g. 2026-09-12.'],
                    'no_action_reason' => ['type' => 'string', 'description' => 'Use only when there is no next action.'],
                ],
                'required' => ['reference'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityUpdate->value;
    }

    public function summarise(User $user, array $arguments): string
    {
        $opportunity = $this->find($arguments);

        if (filled($arguments['next_action'] ?? null)) {
            $summary = 'Set next action on "'.$opportunity->title.'" to "'.$arguments['next_action'].'"';

            return $summary.(filled($arguments['next_follow_up_at'] ?? null)
                ? ', following up on '.$arguments['next_follow_up_at'].'.'
                : '.');
        }

        return 'Record that "'.$opportunity->title.'" has no next action, because: '
            .($arguments['no_action_reason'] ?? 'no reason given').'.';
    }

    public function execute(User $user, array $arguments): array
    {
        $opportunity = $this->find($arguments);

        Gate::forUser($user)->authorize('update', $opportunity);
        Auth::setUser($user);

        $updated = $this->opportunities->setNextAction(
            $opportunity,
            $arguments['next_action'] ?? null,
            filled($arguments['next_follow_up_at'] ?? null) ? new \DateTimeImmutable($arguments['next_follow_up_at']) : null,
            $arguments['no_action_reason'] ?? null,
        );

        return [
            'updated' => true,
            'reference' => $updated->uuid,
            'next_action' => $updated->next_action,
            'next_follow_up_at' => $updated->next_follow_up_at?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function find(array $arguments): Opportunity
    {
        $opportunity = Opportunity::query()->where('uuid', $arguments['reference'] ?? '')->first();

        if ($opportunity === null) {
            throw ValidationException::withMessages(['reference' => 'No opportunity with that reference.']);
        }

        return $opportunity;
    }
}
