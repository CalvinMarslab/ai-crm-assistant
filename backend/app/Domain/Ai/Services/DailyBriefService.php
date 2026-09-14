<?php

namespace App\Domain\Ai\Services;

use App\Domain\Dashboard\Services\DashboardService;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Models\User;
use App\Support\OrganizationClock;
use Illuminate\Support\Collection;

/**
 * The daily brief from AI_ASSISTANT_SPEC.md section 5.
 *
 * Computed entirely from the database. No model is consulted, which is
 * deliberate: a brief is a statement of fact about the business, and the one
 * thing it must never do is invent an overdue task. It therefore also works
 * before any LLM provider is configured, and it is what the Telegram morning
 * message sends.
 *
 * The "suggested actions" section is derived from the same facts by fixed
 * rules, and is labelled as suggestion rather than record.
 */
class DailyBriefService
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly OrganizationClock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $overdueTasks = $this->dashboard->overdueTasks($user, 15);
        $dueToday = $this->dashboard->tasksDueToday($user, 15);
        $followUps = $this->dashboard->followUpsDue($user, 15);
        $noNextAction = $this->dashboard->withoutNextAction($user, 10);
        $proposals = $this->dashboard->proposalsAwaitingResponse($user, 10);
        $atRisk = $this->dashboard->highValueAtRisk($user, 5);
        $projects = $this->projectsNeedingAttention($user);

        return [
            'generated_at' => $this->clock->now()->toIso8601String(),
            'for' => $user->name,
            'timezone' => $this->clock->timezone(),
            'top_priorities' => $this->topPriorities($overdueTasks, $followUps, $atRisk),
            'sections' => [
                'overdue_tasks' => $overdueTasks->map($this->task(...))->all(),
                'tasks_due_today' => $dueToday->map($this->task(...))->all(),
                'follow_ups_due' => $followUps->map($this->opportunity(...))->all(),
                'opportunities_without_next_action' => $noNextAction->map($this->opportunity(...))->all(),
                'proposals_awaiting_response' => $proposals->map($this->opportunity(...))->all(),
                'high_value_at_risk' => $atRisk->map($this->opportunity(...))->all(),
                'projects_requiring_update' => $projects,
            ],
            'suggested_actions' => $this->suggestions($overdueTasks, $followUps, $noNextAction, $proposals, $projects),
            'counts' => [
                'overdue_tasks' => $overdueTasks->count(),
                'due_today' => $dueToday->count(),
                'follow_ups_due' => $followUps->count(),
                'without_next_action' => $noNextAction->count(),
                'proposals_awaiting_response' => $proposals->count(),
                'projects_requiring_update' => count($projects),
            ],
        ];
    }

    public function isEmpty(User $user): bool
    {
        $brief = $this->for($user);

        return array_sum($brief['counts']) === 0;
    }

    /**
     * The handful of things worth doing first: what is latest, then what is
     * owed to a customer today, then the biggest deal drifting.
     *
     * @param  Collection<int, Task>  $overdueTasks
     * @param  Collection<int, Opportunity>  $followUps
     * @param  Collection<int, Opportunity>  $atRisk
     * @return array<int, array<string, mixed>>
     */
    private function topPriorities(Collection $overdueTasks, Collection $followUps, Collection $atRisk): array
    {
        $priorities = [];

        foreach ($overdueTasks->take(3) as $task) {
            $priorities[] = [
                'why' => 'Overdue',
                'what' => $task->title,
                'detail' => $task->due_at === null
                    ? null
                    : $task->due_at->diffForHumans().' · '.($task->subject?->title ?? $task->subject?->name ?? 'no linked record'),
                'reference' => $task->uuid,
                'type' => 'task',
            ];
        }

        foreach ($followUps->take(3) as $opportunity) {
            $priorities[] = [
                'why' => 'Follow-up due',
                'what' => $opportunity->title,
                'detail' => trim(($opportunity->company?->name ?? '').' · '.($opportunity->next_action ?? 'no next action recorded')),
                'reference' => $opportunity->uuid,
                'type' => 'opportunity',
            ];
        }

        foreach ($atRisk->take(2) as $opportunity) {
            $priorities[] = [
                'why' => 'High value, going quiet',
                'what' => $opportunity->title,
                'detail' => ($opportunity->company?->name ?? '').' · last contact '
                    .($opportunity->last_contact_at?->diffForHumans() ?? 'never recorded'),
                'reference' => $opportunity->uuid,
                'type' => 'opportunity',
            ];
        }

        return array_slice($priorities, 0, 6);
    }

    /**
     * Fixed rules over the same facts, so the wording is stable and nothing is
     * invented. Phrased as recommendations, never as record.
     *
     * @param  Collection<int, Task>  $overdue
     * @param  Collection<int, Opportunity>  $followUps
     * @param  Collection<int, Opportunity>  $noNextAction
     * @param  Collection<int, Opportunity>  $proposals
     * @param  array<int, array<string, mixed>>  $projects
     * @return array<int, string>
     */
    private function suggestions(
        Collection $overdue,
        Collection $followUps,
        Collection $noNextAction,
        Collection $proposals,
        array $projects,
    ): array {
        $suggestions = [];

        if ($overdue->isNotEmpty()) {
            $suggestions[] = 'Clear the '.$overdue->count().' overdue '
                .str('task')->plural($overdue->count())
                .' first, or move '.($overdue->count() === 1 ? 'the date' : 'the dates')
                .' if they are no longer real.';
        }

        if ($followUps->isNotEmpty()) {
            $suggestions[] = 'Contact '.$followUps->take(3)->map(fn ($o) => $o->company?->name ?? $o->title)->join(', ', ' and ')
                .' — their follow-up date has arrived.';
        }

        if ($noNextAction->isNotEmpty()) {
            $count = $noNextAction->count();
            $suggestions[] = $count.' open '.str('opportunity')->plural($count)
                .($count === 1 ? ' has' : ' have').' no next action. '
                .($count === 1 ? 'Give it a next step, or record why there is none.'
                    : 'Give each one a next step, or record why there is none.');
        }

        if ($proposals->isNotEmpty()) {
            $oldest = $proposals->first();
            $suggestions[] = 'Chase the quotation on '.($oldest->company?->name ?? $oldest->title)
                .($oldest->quotation_sent_at ? ', sent '.$oldest->quotation_sent_at->diffForHumans() : '').'.';
        }

        foreach (array_slice($projects, 0, 2) as $project) {
            $suggestions[] = 'Update '.$project['name'].' — '.strtolower($project['reason']).'.';
        }

        return $suggestions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectsNeedingAttention(User $user): array
    {
        if (! $user->canDoAny([PermissionCode::ProjectViewAll, PermissionCode::ProjectViewAssigned])) {
            return [];
        }

        $query = Project::query()->open();

        if (! $user->canDo(PermissionCode::ProjectViewAll)) {
            $query->forManager($user->id);
        }

        $stale = $this->clock->daysAgo(7);

        return $query
            ->with(['company:id,name', 'manager:id,name'])
            ->get()
            ->map(function (Project $project) use ($stale) {
                $reason = match (true) {
                    $project->project_manager_user_id === null => 'No project manager assigned',
                    $project->status->isBlocked() => 'Waiting: '.$project->status->label(),
                    $project->status->value === 'pending_handover' && ! $project->handoverComplete() => 'Handover checklist unfinished',
                    $project->updated_at !== null && $project->updated_at->lt($stale) => 'No update for over a week',
                    default => null,
                };

                return $reason === null ? null : [
                    'reference' => $project->uuid,
                    'name' => $project->name,
                    'company' => $project->company?->name,
                    'status' => $project->status->label(),
                    'manager' => $project->manager?->name,
                    'reason' => $reason,
                ];
            })
            ->filter()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function task(Task $task): array
    {
        return [
            'reference' => $task->uuid,
            'title' => $task->title,
            'due_at' => $task->due_at?->toDateTimeString(),
            'overdue_by' => $task->isOverdue() ? $task->due_at?->diffForHumans() : null,
            'assignee' => $task->assignee?->name,
            'attached_to' => $task->subject?->title ?? $task->subject?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function opportunity(Opportunity $opportunity): array
    {
        return [
            'reference' => $opportunity->uuid,
            'title' => $opportunity->title,
            'company' => $opportunity->company?->name,
            'stage' => $opportunity->stage?->name,
            'owner' => $opportunity->owner?->name,
            'estimated_value' => $opportunity->estimated_value === null ? null : (float) $opportunity->estimated_value,
            'next_action' => $opportunity->next_action,
            'next_follow_up_at' => $opportunity->next_follow_up_at?->toDateString(),
            'last_contact_at' => $opportunity->last_contact_at?->toDateString(),
        ];
    }
}
