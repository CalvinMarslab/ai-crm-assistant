<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Pipeline\Enums\StageType;
use Illuminate\Support\Facades\DB;

/**
 * Agent performance derived entirely from linked opportunities (PRD section 8),
 * so numbers can never drift from the pipeline they describe.
 */
class AgentStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function for(Agent $agent): array
    {
        $base = Opportunity::query()->forReferralAgent($agent->id);

        $counts = (clone $base)
            ->select('status', DB::raw('COUNT(*) as total'), DB::raw('SUM(COALESCE(final_value, estimated_value, 0)) as value'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $introduced = (int) $counts->sum('total');
        $won = (int) ($counts[StageType::Won->value]->total ?? 0);
        $lost = (int) ($counts[StageType::Lost->value]->total ?? 0);
        $active = (int) (($counts[StageType::Open->value]->total ?? 0) + ($counts[StageType::Hold->value]->total ?? 0));

        $decided = $won + $lost;

        return [
            'introduced' => $introduced,
            'active' => $active,
            'won' => $won,
            'lost' => $lost,
            'estimated_value' => round((float) (clone $base)->open()->sum('estimated_value'), 2),
            'won_value' => round((float) (clone $base)->where('status', StageType::Won->value)->sum(DB::raw('COALESCE(final_value, estimated_value, 0)')), 2),
            'conversion_rate' => $decided > 0 ? round($won / $decided * 100, 1) : null,
            'by_stage' => $this->byStage($agent),
        ];
    }

    /**
     * @return array<int, array{stage: string, code: string, count: int, value: float}>
     */
    private function byStage(Agent $agent): array
    {
        return Opportunity::query()
            ->forReferralAgent($agent->id)
            ->open()
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'opportunities.stage_id')
            ->select(
                'pipeline_stages.name as stage',
                'pipeline_stages.code as code',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(COALESCE(opportunities.estimated_value, 0)) as value'),
            )
            ->groupBy('pipeline_stages.id', 'pipeline_stages.name', 'pipeline_stages.code', 'pipeline_stages.sequence')
            ->orderBy('pipeline_stages.sequence')
            ->get()
            ->map(fn ($row) => [
                'stage' => $row->stage,
                'code' => $row->code,
                'count' => (int) $row->count,
                'value' => round((float) $row->value, 2),
            ])
            ->all();
    }
}
