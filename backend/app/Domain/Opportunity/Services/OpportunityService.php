<?php

namespace App\Domain\Opportunity\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Agent\Models\Agent;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Models\OpportunityStageHistory;
use App\Domain\Company\Models\Contact;
use App\Domain\Pipeline\Models\Pipeline;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpportunityService
{
    public function __construct(
        private readonly ActivityRecorder $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Opportunity
    {
        return DB::transaction(function () use ($attributes) {
            $pipeline = isset($attributes['pipeline_id'])
                ? Pipeline::findOrFail($attributes['pipeline_id'])
                : Pipeline::default();

            // Constrained through the pipeline relation: PipelineStage carries no
            // organization_id of its own, so a bare findOrFail would happily
            // return a stage from another organization's pipeline.
            $stage = isset($attributes['stage_id'])
                ? $pipeline->stages()->whereKey($attributes['stage_id'])->first()
                : $pipeline->stages()->where('is_active', true)->orderBy('sequence')->first();

            if ($stage === null) {
                throw ValidationException::withMessages([
                    'stage_id' => 'The selected stage does not belong to this pipeline.',
                ]);
            }

            if (isset($attributes['primary_contact_id'])) {
                $this->guardContactBelongsToCompany($attributes['company_id'], $attributes['primary_contact_id']);
            }

            $attributes['pipeline_id'] = $pipeline->id;
            $attributes['stage_id'] = $stage->id;
            $attributes['status'] = $stage->stage_type->value;
            $attributes['owner_user_id'] ??= Auth::id();
            $attributes['probability'] ??= $stage->probability_default;

            $opportunity = Opportunity::create($attributes);

            // The opening entry of the stage history, so the funnel is complete
            // from the first moment rather than from the first change.
            OpportunityStageHistory::create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => null,
                'to_stage_id' => $stage->id,
                'changed_by_user_id' => Auth::id(),
                'changed_at' => now(),
                'note' => 'Opportunity created',
            ]);

            $this->activities->record(
                type: ActivityType::OpportunityCreated,
                subject: $opportunity,
                title: "Opportunity created: {$opportunity->title}",
                metadata: [
                    'stage' => $stage->code,
                    'source' => $opportunity->leadSource?->code,
                    'referral_agent_id' => $opportunity->referral_agent_id,
                ],
            );

            return $opportunity->load(['company', 'stage', 'owner', 'referralAgent', 'leadSource']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Opportunity $opportunity, array $attributes): Opportunity
    {
        // Stage moves have their own rules; they never travel through a generic update.
        unset($attributes['stage_id'], $attributes['status'], $attributes['won_at'], $attributes['lost_at']);

        if (array_key_exists('primary_contact_id', $attributes) && $attributes['primary_contact_id'] !== null) {
            $this->guardContactBelongsToCompany(
                $attributes['company_id'] ?? $opportunity->company_id,
                $attributes['primary_contact_id'],
            );
        }

        return DB::transaction(function () use ($opportunity, $attributes) {
            $original = $opportunity->getOriginal();
            $opportunity->fill($attributes);
            $changed = array_keys($opportunity->getDirty());
            $opportunity->save();

            if ($changed !== []) {
                $this->recordFieldChanges($opportunity, $original, $changed);
            }

            return $opportunity->fresh(['company', 'stage', 'owner', 'referralAgent', 'leadSource', 'primaryContact']);
        });
    }

    public function setNextAction(
        Opportunity $opportunity,
        ?string $nextAction,
        ?\DateTimeInterface $followUpAt,
        ?string $noActionReason = null,
    ): Opportunity {
        // A closed deal has no "next" — re-opening it is a stage change, not a
        // field edit, otherwise won/lost records drift back onto the action lists.
        if (! $opportunity->isOpen()) {
            throw ValidationException::withMessages([
                'next_action' => 'This opportunity is closed. Change its stage before setting a next action.',
            ]);
        }

        return DB::transaction(function () use ($opportunity, $nextAction, $followUpAt, $noActionReason) {
            $opportunity->update([
                'next_action' => $nextAction,
                'next_follow_up_at' => $followUpAt,
                'no_action_reason' => filled($nextAction) ? null : $noActionReason,
            ]);

            $this->activities->record(
                type: ActivityType::NextActionChanged,
                subject: $opportunity,
                title: filled($nextAction)
                    ? "Next action set: {$nextAction}"
                    : 'Next action cleared: '.($noActionReason ?? 'no reason given'),
                metadata: [
                    'next_action' => $nextAction,
                    'next_follow_up_at' => $followUpAt?->format(DATE_ATOM),
                    'no_action_reason' => $noActionReason,
                ],
            );

            return $opportunity->fresh();
        });
    }

    public function reassignOwner(Opportunity $opportunity, User $newOwner): Opportunity
    {
        $previous = $opportunity->owner;

        return DB::transaction(function () use ($opportunity, $newOwner, $previous) {
            $opportunity->update(['owner_user_id' => $newOwner->id]);

            $this->activities->record(
                type: ActivityType::OwnerChanged,
                subject: $opportunity,
                title: "Owner changed from {$previous?->name} to {$newOwner->name}",
                metadata: ['from_user_id' => $previous?->id, 'to_user_id' => $newOwner->id],
            );

            return $opportunity->fresh('owner');
        });
    }

    public function reassignAgent(Opportunity $opportunity, ?Agent $agent): Opportunity
    {
        $previous = $opportunity->referralAgent;

        return DB::transaction(function () use ($opportunity, $agent, $previous) {
            $opportunity->update(['referral_agent_id' => $agent?->id]);

            $this->activities->record(
                type: ActivityType::AgentChanged,
                subject: $opportunity,
                title: 'Referral agent changed from '
                    .($previous?->name ?? 'none').' to '.($agent?->name ?? 'none'),
                metadata: ['from_agent_id' => $previous?->id, 'to_agent_id' => $agent?->id],
            );

            return $opportunity->fresh('referralAgent');
        });
    }

    public function addNote(Opportunity $opportunity, string $body, bool $isInternal, ActivityType $type): void
    {
        DB::transaction(function () use ($opportunity, $body, $isInternal, $type) {
            $this->activities->record(
                type: $type,
                subject: $opportunity,
                title: $type->label(),
                body: $body,
                isInternal: $isInternal,
            );

            // Logging contact is what keeps the "recently inactive" signal honest.
            if (in_array($type, [ActivityType::CallLogged, ActivityType::MeetingLogged, ActivityType::CustomerReplyNoted], true)) {
                $opportunity->update(['last_contact_at' => now()]);
            }
        });
    }

    /**
     * A contact attached to an opportunity must belong to that opportunity's
     * company, otherwise the record claims a relationship that does not exist.
     */
    private function guardContactBelongsToCompany(int|string|null $companyId, int|string $contactId): void
    {
        $belongs = Contact::whereKey($contactId)->where('company_id', $companyId)->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'primary_contact_id' => 'The selected contact does not belong to this company.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<int, string>  $changed
     */
    private function recordFieldChanges(Opportunity $opportunity, array $original, array $changed): void
    {
        $notable = [
            'quotation_status' => ActivityType::QuotationUpdated,
            'quotation_amount' => ActivityType::QuotationUpdated,
            'next_follow_up_at' => ActivityType::FollowUpChanged,
            'next_action' => ActivityType::NextActionChanged,
        ];

        foreach ($notable as $field => $type) {
            if (! in_array($field, $changed, true)) {
                continue;
            }

            $this->activities->record(
                type: $type,
                subject: $opportunity,
                title: str($field)->replace('_', ' ')->headline()->toString().' updated',
                metadata: ['field' => $field, 'from' => $original[$field] ?? null, 'to' => $opportunity->getAttribute($field)],
            );

            return;
        }

        $this->activities->record(
            type: ActivityType::OpportunityUpdated,
            subject: $opportunity,
            title: 'Opportunity details updated',
            metadata: ['fields' => array_values(array_diff($changed, ['updated_at']))],
        );
    }
}
