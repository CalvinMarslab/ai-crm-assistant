<?php

namespace App\Domain\Dashboard\Services;

use App\Domain\Activity\Models\Activity;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\OpportunityHygieneService;
use App\Domain\Pipeline\Enums\StageType;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The execution view. Sections come before metrics on purpose: the UX rule is
 * that the dashboard prioritises actions, not vanity numbers.
 *
 * These same queries back the Phase 3 AI tools (get_pipeline_summary,
 * get_overdue_tasks, daily brief), which is why they live in a service.
 */
class DashboardService
{
    public function __construct(
        private readonly OpportunityHygieneService $hygiene,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        return [
            'sections' => [
                'overdue_tasks' => $this->overdueTasks($user),
                'tasks_due_today' => $this->tasksDueToday($user),
                'follow_ups_due' => $this->followUpsDue($user),
                'without_next_action' => $this->withoutNextAction($user),
                'proposals_awaiting_response' => $this->proposalsAwaitingResponse($user),
                'high_value_at_risk' => $this->highValueAtRisk($user),
                'recently_inactive' => $this->recentlyInactive($user),
            ],
            'metrics' => $this->metrics($user),
            'stage_distribution' => $this->stageDistribution($user),
            'recent_activity' => $this->recentActivity($user),
            'meta' => [
                'inactivity_threshold_days' => $this->hygiene->inactivityThresholdDays(),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return Collection<int, Task>
     */
    public function overdueTasks(User $user, int $limit = 10): Collection
    {
        return $this->taskScope($user)->overdue()
            ->with(['assignee:id,uuid,name', 'subject'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Task>
     */
    public function tasksDueToday(User $user, int $limit = 10): Collection
    {
        return $this->taskScope($user)->dueToday()
            ->with(['assignee:id,uuid,name', 'subject'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Opportunity>
     */
    public function followUpsDue(User $user, int $limit = 10): Collection
    {
        return $this->opportunityScope($user)
            ->followUpDueBy(now()->endOfDay())
            ->with($this->opportunityRelations())
            ->orderBy('next_follow_up_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Opportunity>
     */
    public function withoutNextAction(User $user, int $limit = 10): Collection
    {
        return $this->opportunityScope($user)
            ->withoutNextAction()
            ->with($this->opportunityRelations())
            ->orderByDesc('estimated_value')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Opportunity>
     */
    public function proposalsAwaitingResponse(User $user, int $limit = 10): Collection
    {
        return $this->opportunityScope($user)
            ->awaitingQuotationResponse()
            ->with($this->opportunityRelations())
            ->orderBy('quotation_sent_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Big deals that have gone quiet — the ones that hurt most when forgotten.
     *
     * @return Collection<int, Opportunity>
     */
    public function highValueAtRisk(User $user, int $limit = 5): Collection
    {
        $threshold = now()->subDays($this->hygiene->inactivityThresholdDays());

        return $this->opportunityScope($user)
            ->open()
            ->whereNotNull('estimated_value')
            ->where(fn (Builder $q) => $q
                ->inactiveSince($threshold)
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereNull('next_action')
                    ->whereNull('no_action_reason')))
            ->with($this->opportunityRelations())
            ->orderByDesc('estimated_value')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Opportunity>
     */
    public function recentlyInactive(User $user, int $limit = 10): Collection
    {
        return $this->opportunityScope($user)
            ->inactiveSince(now()->subDays($this->hygiene->inactivityThresholdDays()))
            ->with($this->opportunityRelations())
            ->orderBy(DB::raw('COALESCE(last_contact_at, updated_at)'))
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(User $user): array
    {
        $monthStart = now()->startOfMonth();

        $won = (clone $this->opportunityScope($user))->where('status', StageType::Won->value);
        $lost = (clone $this->opportunityScope($user))->where('status', StageType::Lost->value);

        $wonCount = (clone $won)->count();
        $lostCount = (clone $lost)->count();
        $decided = $wonCount + $lostCount;

        return [
            'leads_this_month' => (clone $this->opportunityScope($user))->where('created_at', '>=', $monthStart)->count(),
            'active_opportunities' => (clone $this->opportunityScope($user))->open()->count(),
            'pipeline_value' => round((float) (clone $this->opportunityScope($user))->open()->sum('estimated_value'), 2),
            'won_value' => round((float) (clone $won)->sum(DB::raw('COALESCE(final_value, estimated_value, 0)')), 2),
            'lost_value' => round((float) (clone $lost)->sum('estimated_value'), 2),
            'won_count' => $wonCount,
            'lost_count' => $lostCount,
            'win_rate' => $decided > 0 ? round($wonCount / $decided * 100, 1) : null,
            'average_sales_cycle_days' => $this->averageSalesCycleDays($user),
            'overdue_task_count' => $this->taskScope($user)->overdue()->count(),
            'without_next_action_count' => (clone $this->opportunityScope($user))->withoutNextAction()->count(),
        ];
    }

    /**
     * @return array<int, array{stage: string, code: string, stage_type: string, count: int, value: float}>
     */
    public function stageDistribution(User $user): array
    {
        return $this->opportunityScope($user)
            ->open()
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'opportunities.stage_id')
            ->select(
                'pipeline_stages.name as stage',
                'pipeline_stages.code as code',
                'pipeline_stages.stage_type as stage_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(COALESCE(opportunities.estimated_value, 0)) as value'),
            )
            ->groupBy('pipeline_stages.id', 'pipeline_stages.name', 'pipeline_stages.code', 'pipeline_stages.stage_type', 'pipeline_stages.sequence')
            ->orderBy('pipeline_stages.sequence')
            ->get()
            ->map(fn ($row) => [
                'stage' => $row->stage,
                'code' => $row->code,
                'stage_type' => $row->stage_type,
                'count' => (int) $row->count,
                'value' => round((float) $row->value, 2),
            ])
            ->all();
    }

    /**
     * @return Collection<int, Activity>
     */
    public function recentActivity(User $user, int $limit = 20): Collection
    {
        return Activity::query()
            ->visibleTo($user)
            ->with(['actor:id,uuid,name', 'company:id,uuid,name'])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    private function averageSalesCycleDays(User $user): ?float
    {
        $average = (clone $this->opportunityScope($user))
            ->whereNotNull('won_at')
            ->avg(DB::raw('TIMESTAMPDIFF(DAY, created_at, won_at)'));

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * Scoping happens once, here, so no dashboard section can accidentally show
     * a referral agent somebody else's pipeline.
     */
    private function opportunityScope(User $user): Builder
    {
        $query = Opportunity::query();

        if ($user->canDo(PermissionCode::OpportunityViewAll)) {
            return $query;
        }

        if ($user->canDo(PermissionCode::OpportunityViewOwnReferrals) && $user->agentProfile !== null) {
            return $query->forReferralAgent($user->agentProfile->id);
        }

        return $query->where('owner_user_id', $user->id);
    }

    private function taskScope(User $user): Builder
    {
        $query = Task::query();

        return $user->canDo(PermissionCode::TaskViewAll)
            ? $query
            : $query->where('assigned_user_id', $user->id);
    }

    /**
     * @return array<int, string>
     */
    private function opportunityRelations(): array
    {
        return ['company:id,uuid,name', 'stage:id,name,code,stage_type', 'owner:id,uuid,name', 'referralAgent:id,uuid,name'];
    }
}
