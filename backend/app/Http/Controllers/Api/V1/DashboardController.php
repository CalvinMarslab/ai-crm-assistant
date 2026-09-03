<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Dashboard\Services\DashboardService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\OpportunityResource;
use App\Http\Resources\TaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * One call returns the whole execution view — the owner should understand
     * business status within 30 seconds, not after five round trips.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->dashboard->forUser($user);

        return response()->json([
            'data' => [
                'sections' => [
                    'overdue_tasks' => TaskResource::collection($data['sections']['overdue_tasks']),
                    'tasks_due_today' => TaskResource::collection($data['sections']['tasks_due_today']),
                    'follow_ups_due' => OpportunityResource::collection($data['sections']['follow_ups_due']),
                    'without_next_action' => OpportunityResource::collection($data['sections']['without_next_action']),
                    'proposals_awaiting_response' => OpportunityResource::collection($data['sections']['proposals_awaiting_response']),
                    'high_value_at_risk' => OpportunityResource::collection($data['sections']['high_value_at_risk']),
                    'top_value_open' => OpportunityResource::collection($data['sections']['top_value_open']),
                    'recently_inactive' => OpportunityResource::collection($data['sections']['recently_inactive']),
                ],
                'metrics' => $data['metrics'],
                'stage_distribution' => $data['stage_distribution'],
                'recent_activity' => ActivityResource::collection($data['recent_activity']),
                'meta' => $data['meta'],
            ],
        ]);
    }

    public function metrics(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'metrics' => $this->dashboard->metrics($request->user()),
                'stage_distribution' => $this->dashboard->stageDistribution($request->user()),
            ],
        ]);
    }
}
