<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = AuditLog::query()
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('subject_type'), fn (Builder $q) => $q->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->whereHas(
                'user', fn (Builder $u) => $u->where('uuid', $request->string('user_id'))
            ))
            ->with('user:id,uuid,name')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return AuditLogResource::collection($logs);
    }

    /**
     * The audit trail for one record, addressed by morph alias and UUID so no
     * internal identifier is required to read it.
     */
    public function forSubject(Request $request, string $subjectType, string $uuid): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $model = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($subjectType);
        abort_if($model === null, 404);

        $subjectId = $model::query()->where('uuid', $uuid)->value('id');
        abort_if($subjectId === null, 404);

        $logs = AuditLog::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('user:id,uuid,name')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return AuditLogResource::collection($logs);
    }
}
