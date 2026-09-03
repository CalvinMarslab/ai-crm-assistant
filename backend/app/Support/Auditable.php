<?php

namespace App\Support;

use App\Domain\Audit\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Architecture rule 2: all state changes must be auditable. Models using this
 * trait write a before/after audit entry on create, update, and delete without
 * each service having to remember to do it.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(AuditRecorder::class)->record('created', $model, null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $watched = property_exists($model, 'auditable') ? $model->auditableAttributes() : null;

            $changes = $watched === null
                ? $model->getChanges()
                : array_intersect_key($model->getChanges(), array_flip($watched));

            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $before = array_intersect_key($model->getOriginal(), $changes);

            app(AuditRecorder::class)->record('updated', $model, $before, $changes);
        });

        static::deleted(function (Model $model) {
            app(AuditRecorder::class)->record('deleted', $model, $model->getOriginal(), null);
        });
    }

    /**
     * @return array<int, string>
     */
    public function auditableAttributes(): array
    {
        return $this->auditable ?? [];
    }
}
