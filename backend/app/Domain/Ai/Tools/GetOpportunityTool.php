<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\OpportunityHygieneService;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** The full picture of one opportunity, including its recent history. */
class GetOpportunityTool extends ReadTool
{
    public function __construct(private readonly OpportunityHygieneService $hygiene) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_opportunity',
            description: 'Full detail and recent timeline for one opportunity, for summarising its history '
                .'or explaining where it stands. Find the reference with search_opportunities first.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'description' => 'The opportunity reference returned by search_opportunities.'],
                    'timeline_limit' => ['type' => 'integer', 'description' => 'How many recent events to include, default 20.'],
                ],
                'required' => ['reference'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityViewOwn->value;
    }

    public function execute(User $user, array $arguments): array
    {
        $opportunity = Opportunity::query()
            ->where('uuid', $arguments['reference'] ?? '')
            ->with(['company', 'primaryContact', 'stage', 'owner', 'referralAgent', 'leadSource', 'project'])
            ->first();

        if ($opportunity === null) {
            throw ValidationException::withMessages(['reference' => 'No opportunity with that reference.']);
        }

        // The assistant inherits the user's reach, never widens it.
        Gate::forUser($user)->authorize('view', $opportunity);

        $showFinancials = Gate::forUser($user)->allows('viewFinancials', $opportunity);
        $showInternal = Gate::forUser($user)->allows('viewInternalNotes', $opportunity);

        $timeline = $opportunity->activities()
            ->when(! $showInternal, fn ($q) => $q->where('is_internal', false))
            ->with('actor:id,name')
            ->limit(min((int) ($arguments['timeline_limit'] ?? 20), 50))
            ->get()
            ->map(fn ($a) => [
                'at' => $a->occurred_at?->toDateTimeString(),
                'type' => $a->activity_type->value,
                'title' => $a->title,
                'note' => $a->body,
                'by' => $a->actor?->name,
            ])->all();

        return array_filter([
            'reference' => $opportunity->uuid,
            'title' => $opportunity->title,
            'company' => $opportunity->company?->name,
            'contact' => $opportunity->primaryContact?->name,
            'stage' => $opportunity->stage?->name,
            'status' => $opportunity->status,
            'owner' => $opportunity->owner?->name,
            'referral_agent' => $opportunity->referralAgent?->name,
            'source' => $opportunity->leadSource?->name,
            'summary' => $opportunity->summary,
            'requirements' => $showFinancials ? $opportunity->requirements : null,
            'estimated_value' => $showFinancials && $opportunity->estimated_value !== null ? (float) $opportunity->estimated_value : null,
            'quotation_status' => $opportunity->quotation_status?->value,
            'quotation_amount' => $showFinancials && $opportunity->quotation_amount !== null ? (float) $opportunity->quotation_amount : null,
            'next_action' => $opportunity->next_action,
            'no_action_reason' => $opportunity->no_action_reason,
            'next_follow_up_at' => $opportunity->next_follow_up_at?->toDateString(),
            'last_contact_at' => $opportunity->last_contact_at?->toDateString(),
            'expected_close_date' => $opportunity->expected_close_date?->toDateString(),
            'loss_reason' => $opportunity->loss_reason,
            'converted_to_project' => $opportunity->project?->name,
            'warnings' => $this->hygiene->warningsFor($opportunity),
            'recent_activity' => $timeline,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
