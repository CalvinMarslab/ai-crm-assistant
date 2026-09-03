<?php

namespace App\Domain\Opportunity\Services;

use App\Domain\Opportunity\Enums\QuotationStatus;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Support\Collection;

/**
 * CRM_WORKFLOW.md section 3. Every rule is expressed as a named warning so the
 * dashboard, the notification job, and the Phase 3 AI risk detector all agree
 * on what "unhealthy" means.
 */
class OpportunityHygieneService
{
    public const NO_OWNER = 'no_owner';

    public const NO_NEXT_ACTION = 'no_next_action';

    public const STALE = 'stale';

    public const PROPOSAL_WITHOUT_FOLLOW_UP = 'proposal_without_follow_up';

    public const CLOSE_DATE_PASSED = 'close_date_passed';

    public const FOLLOW_UP_OVERDUE = 'follow_up_overdue';

    /**
     * @return array<int, array{code: string, message: string}>
     */
    public function warningsFor(Opportunity $opportunity): array
    {
        if (! $opportunity->isOpen()) {
            return [];
        }

        $warnings = [];
        $thresholdDays = $this->inactivityThresholdDays();

        if ($opportunity->owner_user_id === null) {
            $warnings[] = ['code' => self::NO_OWNER, 'message' => 'No owner assigned.'];
        }

        if (! $opportunity->hasNextAction()) {
            $warnings[] = ['code' => self::NO_NEXT_ACTION, 'message' => 'No next action or reason recorded.'];
        }

        $lastTouch = $opportunity->last_contact_at ?? $opportunity->updated_at;

        if ($lastTouch !== null && $lastTouch->lt(now()->subDays($thresholdDays))) {
            $warnings[] = [
                'code' => self::STALE,
                'message' => "No update for {$lastTouch->diffInDays(now())} days (threshold {$thresholdDays}).",
            ];
        }

        if ($opportunity->quotation_status instanceof QuotationStatus
            && $opportunity->quotation_status->awaitingCustomer()
            && $opportunity->next_follow_up_at === null) {
            $warnings[] = [
                'code' => self::PROPOSAL_WITHOUT_FOLLOW_UP,
                'message' => 'Proposal sent but no follow-up date is set.',
            ];
        }

        if ($opportunity->expected_close_date !== null && $opportunity->expected_close_date->isPast()) {
            $warnings[] = [
                'code' => self::CLOSE_DATE_PASSED,
                'message' => 'Expected close date has passed.',
            ];
        }

        if ($opportunity->next_follow_up_at !== null && $opportunity->next_follow_up_at->isPast()) {
            $warnings[] = [
                'code' => self::FOLLOW_UP_OVERDUE,
                'message' => 'Follow-up date has passed.',
            ];
        }

        return $warnings;
    }

    /**
     * Opportunities that are open but have gone quiet.
     *
     * @return Collection<int, Opportunity>
     */
    public function inactive(?int $days = null, int $limit = 10): Collection
    {
        return Opportunity::query()
            ->inactiveSince(now()->subDays($days ?? $this->inactivityThresholdDays()))
            ->with(['company:id,uuid,name', 'stage:id,name,code', 'owner:id,uuid,name'])
            ->orderBy('last_contact_at')
            ->limit($limit)
            ->get();
    }

    public function inactivityThresholdDays(): int
    {
        $organizationId = OrganizationContext::id();

        if ($organizationId === null) {
            return (int) config('crm.inactivity_threshold_days');
        }

        return Organization::find($organizationId)?->inactivityThresholdDays()
            ?? (int) config('crm.inactivity_threshold_days');
    }
}
