<?php

namespace App\Models\Concerns;

use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant preparation (SYSTEM_ARCHITECTURE.md section 4). Every query is
 * scoped to the acting user's organization, and organization_id is stamped on
 * create, so cross-tenant leakage is not something each query has to remember.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            $organizationId = OrganizationContext::id();

            if ($organizationId !== null) {
                $query->where($query->getModel()->getTable().'.organization_id', $organizationId);
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->organization_id)) {
                $model->organization_id = OrganizationContext::id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
