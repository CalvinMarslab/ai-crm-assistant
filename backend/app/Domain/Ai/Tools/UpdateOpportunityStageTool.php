<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Data\StageChangeData;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\StageTransitionService;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateOpportunityStageTool extends WriteTool
{
    public function __construct(private readonly StageTransitionService $transitions) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'update_opportunity_stage',
            description: 'Move an opportunity to a different pipeline stage. Marking a deal lost requires '
                .'a loss reason; marking it won requires the final value. The user confirms first.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string'],
                    'stage_code' => ['type' => 'string', 'description' => 'Internal stage code, e.g. proposal_sent.'],
                    'note' => ['type' => 'string'],
                    'loss_reason' => ['type' => 'string', 'description' => 'Required when moving to a lost stage.'],
                    'final_value' => ['type' => 'number', 'description' => 'Required when moving to a won stage.'],
                ],
                'required' => ['reference', 'stage_code'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityChangeStage->value;
    }

    public function summarise(User $user, array $arguments): string
    {
        [$opportunity, $stage] = $this->find($arguments);

        $summary = 'Move "'.$opportunity->title.'" from '.$opportunity->stage?->name.' to '.$stage->name;

        if ($stage->isLost()) {
            $summary .= ', lost because: '.($arguments['loss_reason'] ?? 'no reason given');
        }

        if ($stage->isWon()) {
            $summary .= ', won at '.number_format((float) ($arguments['final_value'] ?? 0), 2);
        }

        return $summary.'.';
    }

    public function execute(User $user, array $arguments): array
    {
        [$opportunity, $stage] = $this->find($arguments);

        Gate::forUser($user)->authorize('changeStage', $opportunity);
        Auth::setUser($user);

        // Straight through the same service a human uses, so stage history,
        // the timeline entry and the won/lost rules all apply unchanged.
        $updated = $this->transitions->change($opportunity, new StageChangeData(
            toStageId: $stage->id,
            note: $arguments['note'] ?? null,
            lossReason: $arguments['loss_reason'] ?? null,
            finalValue: isset($arguments['final_value']) ? (float) $arguments['final_value'] : null,
        ));

        return ['updated' => true, 'reference' => $updated->uuid, 'stage' => $updated->stage?->name];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: Opportunity, 1: PipelineStage}
     */
    private function find(array $arguments): array
    {
        $opportunity = Opportunity::query()->where('uuid', $arguments['reference'] ?? '')->with('stage')->first();

        if ($opportunity === null) {
            throw ValidationException::withMessages(['reference' => 'No opportunity with that reference.']);
        }

        $stage = PipelineStage::query()
            ->where('pipeline_id', $opportunity->pipeline_id)
            ->where('code', $arguments['stage_code'] ?? '')
            ->first();

        if ($stage === null) {
            throw ValidationException::withMessages(['stage_code' => 'No such stage in this opportunity\'s pipeline.']);
        }

        return [$opportunity, $stage];
    }
}
