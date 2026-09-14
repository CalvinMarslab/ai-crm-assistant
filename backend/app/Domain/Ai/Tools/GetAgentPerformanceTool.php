<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentStatsService;
use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class GetAgentPerformanceTool extends ReadTool
{
    public function __construct(private readonly AgentStatsService $stats) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_agent_performance',
            description: 'Referral agent performance: how much each introduced, how much converted, '
                .'and what is still live. Name one agent, or omit to compare all of them.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'agent_name' => ['type' => 'string', 'description' => 'Omit to return every agent.'],
                ],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::AgentViewAll->value;
    }

    public function execute(User $user, array $arguments): array
    {
        $agents = Agent::query()
            ->when(filled($arguments['agent_name'] ?? null),
                fn ($q) => $q->where('name', 'like', '%'.$arguments['agent_name'].'%'))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->filter(fn (Agent $agent) => Gate::forUser($user)->allows('view', $agent));

        return [
            'agents' => $agents->map(fn (Agent $agent) => [
                'name' => $agent->name,
                'company' => $agent->company_name,
                'status' => $agent->status,
                'performance' => $this->stats->for($agent),
            ])->values()->all(),
        ];
    }
}
