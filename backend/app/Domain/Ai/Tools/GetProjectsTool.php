<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery-side visibility, so "which projects are waiting for customer
 * feedback" and "what is blocked" can be answered.
 */
class GetProjectsTool extends ReadTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_projects',
            description: 'Projects and their delivery status. Use blocked_only for projects waiting on '
                .'the customer or on hold, and needs_handover for projects still not handed over.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['pending_handover', 'planning', 'in_progress', 'waiting_customer', 'internal_review', 'completed', 'on_hold'],
                    ],
                    'blocked_only' => ['type' => 'boolean', 'description' => 'Waiting for customer, or on hold.'],
                    'needs_handover' => ['type' => 'boolean', 'description' => 'Still in pending handover.'],
                    'limit' => ['type' => 'integer', 'description' => 'Default 25, capped at 50.'],
                ],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::ProjectViewAssigned->value;
    }

    public function execute(User $user, array $arguments): array
    {
        $query = Project::query();

        // Scoped exactly as the projects screen is.
        if (! $user->canDo(PermissionCode::ProjectViewAll)) {
            $query->forManager($user->id);
        }

        $projects = $query
            ->when(filled($arguments['status'] ?? null), fn (Builder $q) => $q->where('status', $arguments['status']))
            ->when($arguments['blocked_only'] ?? false, fn (Builder $q) => $q->whereIn('status', ['waiting_customer', 'on_hold']))
            ->when($arguments['needs_handover'] ?? false, fn (Builder $q) => $q->where('status', 'pending_handover'))
            ->with(['company:id,name', 'manager:id,name', 'handoverItems'])
            ->withCount('openTasks')
            ->orderByDesc('updated_at')
            ->limit(min((int) ($arguments['limit'] ?? 25), 50))
            ->get();

        return [
            'count' => $projects->count(),
            'projects' => $projects->map(fn (Project $p) => [
                'reference' => $p->uuid,
                'name' => $p->name,
                'company' => $p->company?->name,
                'status' => $p->status->label(),
                'is_blocked' => $p->status->isBlocked(),
                'manager' => $p->manager?->name,
                'handover_complete' => $p->handoverComplete(),
                'open_tasks' => $p->open_tasks_count,
                'target_end_date' => $p->target_end_date?->toDateString(),
                'last_updated' => $p->updated_at?->toDateString(),
            ])->all(),
        ];
    }
}
