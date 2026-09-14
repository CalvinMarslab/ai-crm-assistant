<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Dashboard\Services\DashboardService;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;

class GetPipelineSummaryTool extends ReadTool
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_pipeline_summary',
            description: 'Headline pipeline numbers and the count of opportunities in each stage. '
                .'Use for "how is the pipeline", win rate, and total value questions.',
            parameters: ['type' => 'object', 'properties' => (object) []],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityViewOwn->value;
    }

    public function execute(User $user, array $arguments): array
    {
        // The same service the dashboard uses, so the assistant can never
        // disagree with the screen the user is looking at.
        return [
            'metrics' => $this->dashboard->metrics($user),
            'by_stage' => $this->dashboard->stageDistribution($user),
        ];
    }
}
