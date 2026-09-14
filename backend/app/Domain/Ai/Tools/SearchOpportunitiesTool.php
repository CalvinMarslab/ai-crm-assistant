<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\OpportunityHygieneService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SearchOpportunitiesTool extends ReadTool
{
    public function __construct(private readonly OpportunityHygieneService $hygiene) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_opportunities',
            description: 'Search opportunities. Use the filters to answer questions about follow-ups, '
                .'stalled deals, quotations awaiting a reply, or deals with no next action. '
                .'Returns only opportunities the user is allowed to see.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Match against title or company name.'],
                    'stage_code' => ['type' => 'string', 'description' => 'Internal pipeline stage code.'],
                    'status' => ['type' => 'string', 'enum' => ['open', 'won', 'lost', 'hold']],
                    'without_next_action' => ['type' => 'boolean', 'description' => 'Only deals with no next action recorded.'],
                    'follow_up_due' => ['type' => 'boolean', 'description' => 'Only deals whose follow-up date has arrived or passed.'],
                    'awaiting_quotation_response' => ['type' => 'boolean', 'description' => 'Only deals where a quotation was sent and no reply is recorded.'],
                    'inactive_days' => ['type' => 'integer', 'description' => 'Only deals with no contact for at least this many days.'],
                    'agent_name' => ['type' => 'string', 'description' => 'Only deals introduced by this referral agent.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum rows, default 20, capped at 50.'],
                ],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityViewAll->value;
    }

    public function execute(User $user, array $arguments): array
    {
        $limit = min((int) ($arguments['limit'] ?? 20), 50);

        $opportunities = Opportunity::query()
            ->when(filled($arguments['search'] ?? null), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('title', 'like', '%'.$arguments['search'].'%')
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', '%'.$arguments['search'].'%'))))
            ->when(filled($arguments['stage_code'] ?? null), fn (Builder $q) => $q->whereHas(
                'stage', fn (Builder $s) => $s->where('code', $arguments['stage_code'])
            ))
            ->when(filled($arguments['status'] ?? null), fn (Builder $q) => $q->where('status', $arguments['status']))
            ->when($arguments['without_next_action'] ?? false, fn (Builder $q) => $q->withoutNextAction())
            ->when($arguments['follow_up_due'] ?? false, fn (Builder $q) => $q->followUpDueBy(now()->endOfDay()))
            ->when($arguments['awaiting_quotation_response'] ?? false, fn (Builder $q) => $q->awaitingQuotationResponse())
            ->when(filled($arguments['inactive_days'] ?? null), fn (Builder $q) => $q->inactiveSince(
                now()->subDays((int) $arguments['inactive_days'])
            ))
            ->when(filled($arguments['agent_name'] ?? null), fn (Builder $q) => $q->whereHas(
                'referralAgent', fn (Builder $a) => $a->where('name', 'like', '%'.$arguments['agent_name'].'%')
            ))
            ->with(['company:id,name', 'stage:id,name,code', 'owner:id,name', 'referralAgent:id,name'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $opportunities->count(),
            'opportunities' => $opportunities->map(fn (Opportunity $o) => [
                'reference' => $o->uuid,
                'title' => $o->title,
                'company' => $o->company?->name,
                'stage' => $o->stage?->name,
                'status' => $o->status,
                'owner' => $o->owner?->name,
                'referral_agent' => $o->referralAgent?->name,
                'estimated_value' => $o->estimated_value === null ? null : (float) $o->estimated_value,
                'next_action' => $o->next_action,
                'no_action_reason' => $o->no_action_reason,
                'next_follow_up_at' => $o->next_follow_up_at?->toDateString(),
                'last_contact_at' => $o->last_contact_at?->toDateString(),
                'expected_close_date' => $o->expected_close_date?->toDateString(),
                'quotation_status' => $o->quotation_status?->value,
                'warnings' => array_column($this->hygiene->warningsFor($o), 'code'),
            ])->all(),
        ];
    }
}
