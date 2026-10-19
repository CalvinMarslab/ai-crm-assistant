<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\LeadSource;
use App\Domain\Pipeline\Models\Pipeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canDoAny([PermissionCode::PipelineView, PermissionCode::OpportunityCreate]), 403);
        $pipelines = Pipeline::with('stages')->get()->map(fn (Pipeline $pipeline) => [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'is_default' => $pipeline->is_default,
            'stages' => $pipeline->stages->map(fn ($stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'code' => $stage->code,
                'sequence' => $stage->sequence,
                'stage_type' => $stage->stage_type->value,
                'agent_facing_status' => $stage->agent_facing_status,
                'probability_default' => $stage->probability_default === null ? null : (float) $stage->probability_default,
                'is_active' => $stage->is_active,
            ])->values(),
        ]);

        return response()->json(['data' => $pipelines]);
    }

    public function leadSources(Request $request): JsonResponse
    {
        abort_unless($request->user()->canDoAny([PermissionCode::PipelineView, PermissionCode::OpportunityCreate]), 403);
        $sources = LeadSource::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (LeadSource $source) => ['code' => $source->code, 'name' => $source->name]);

        return response()->json(['data' => $sources]);
    }
}
