<?php

namespace App\Domain\Opportunity\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Opportunity\Data\StageChangeData;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Models\OpportunityStageHistory;
use App\Domain\Pipeline\Enums\StageType;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Domain\Task\Enums\TaskStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single path through which an opportunity changes stage. Every transition
 * writes stage history and a timeline entry, and the Won/Lost rules from
 * CRM_WORKFLOW.md sections 4 and 5 are enforced here rather than in a controller.
 */
class StageTransitionService
{
    public function __construct(
        private readonly ActivityRecorder $activities,
    ) {}

    public function change(Opportunity $opportunity, StageChangeData $data): Opportunity
    {
        $toStage = PipelineStage::findOrFail($data->toStageId);

        if ($toStage->pipeline_id !== $opportunity->pipeline_id) {
            throw ValidationException::withMessages([
                'stage_id' => 'The selected stage does not belong to this opportunity\'s pipeline.',
            ]);
        }

        $fromStage = $opportunity->stage;

        if ($fromStage->id === $toStage->id) {
            return $opportunity;
        }

        $this->guardTerminalRequirements($toStage, $data);

        return DB::transaction(function () use ($opportunity, $fromStage, $toStage, $data) {
            $opportunity->fill($this->attributesFor($toStage, $data));
            $opportunity->save();

            OpportunityStageHistory::create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => $fromStage->id,
                'to_stage_id' => $toStage->id,
                'changed_by_user_id' => Auth::id(),
                'changed_at' => now(),
                'note' => $data->note,
            ]);

            $this->activities->record(
                type: $this->activityTypeFor($toStage),
                subject: $opportunity,
                title: "Stage changed from {$fromStage->name} to {$toStage->name}",
                body: $data->note,
                // final_value is deliberately absent: activity metadata is returned
                // verbatim on the timeline, which referral agents can read.
                metadata: [
                    'from_stage' => $fromStage->code,
                    'to_stage' => $toStage->code,
                    'loss_reason' => $data->lossReason,
                ],
            );

            if ($toStage->isLost()) {
                $this->cancelOutstandingTasks($opportunity);
            }

            return $opportunity->fresh(['stage', 'company', 'owner', 'referralAgent']);
        });
    }

    /**
     * Won requires a final value and Lost requires a reason — the two facts the
     * business loses forever if they are not captured at the moment of closing.
     */
    private function guardTerminalRequirements(PipelineStage $toStage, StageChangeData $data): void
    {
        if ($toStage->isLost() && blank($data->lossReason)) {
            throw ValidationException::withMessages([
                'loss_reason' => 'A loss reason is required when marking an opportunity as lost.',
            ]);
        }

        if ($toStage->isWon() && $data->finalValue === null) {
            throw ValidationException::withMessages([
                'final_value' => 'A final value is required when marking an opportunity as won.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(PipelineStage $toStage, StageChangeData $data): array
    {
        $attributes = [
            'stage_id' => $toStage->id,
            'status' => $toStage->stage_type->value,
        ];

        if ($toStage->probability_default !== null) {
            $attributes['probability'] = $toStage->probability_default;
        }

        if ($data->nextAction !== null) {
            $attributes['next_action'] = $data->nextAction;
            $attributes['no_action_reason'] = null;
        }

        if ($data->nextFollowUpAt !== null) {
            $attributes['next_follow_up_at'] = $data->nextFollowUpAt;
        }

        if ($toStage->isWon()) {
            $attributes['won_at'] = now();
            $attributes['lost_at'] = null;
            $attributes['final_value'] = $data->finalValue;
            $attributes['probability'] = 100;
            // A won deal's open work is finished; the next action belongs to the
            // project. Leaving a follow-up date behind would keep the closed deal
            // showing up in "follow-ups due" forever.
            $attributes['next_action'] = null;
            $attributes['next_follow_up_at'] = null;
            $attributes['no_action_reason'] = 'Closed won — pending project handover';
        }

        if ($toStage->isLost()) {
            $attributes['lost_at'] = now();
            $attributes['won_at'] = null;
            $attributes['loss_reason'] = $data->lossReason;
            $attributes['loss_note'] = $data->lossNote;
            $attributes['probability'] = 0;
            $attributes['next_action'] = null;
            $attributes['next_follow_up_at'] = null;
            $attributes['no_action_reason'] = 'Closed lost';
        }

        return $attributes;
    }

    private function activityTypeFor(PipelineStage $toStage): ActivityType
    {
        return match ($toStage->stage_type) {
            StageType::Won => ActivityType::OpportunityWon,
            StageType::Lost => ActivityType::OpportunityLost,
            default => ActivityType::StageChanged,
        };
    }

    /**
     * CRM_WORKFLOW.md section 4: close outstanding sales tasks when a deal is lost.
     */
    private function cancelOutstandingTasks(Opportunity $opportunity): void
    {
        $opportunity->openTasks()->get()->each(function ($task) {
            $task->update([
                'status' => TaskStatus::Cancelled,
                'completed_at' => now(),
            ]);
        });
    }
}
