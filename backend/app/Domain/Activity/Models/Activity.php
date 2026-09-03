<?php

namespace App\Domain\Activity\Models;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Company\Models\Company;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use BelongsToOrganization;
    use HasUuid;

    protected $fillable = [
        'actor_user_id', 'activity_type', 'subject_type', 'subject_id',
        'company_id', 'title', 'body', 'is_internal', 'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'activity_type' => ActivityType::class,
            'metadata' => 'array',
            'is_internal' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Referral agents never see internal notes (USER_ROLES_PERMISSION.md). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->canDo(\App\Domain\Identity\Enums\PermissionCode::OpportunityViewInternalNotes)
            ? $query
            : $query->where('is_internal', false);
    }
}
