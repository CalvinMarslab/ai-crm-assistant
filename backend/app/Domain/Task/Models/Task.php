<?php

namespace App\Domain\Task\Models;

use App\Domain\Opportunity\Enums\Priority;
use App\Domain\Task\Enums\TaskStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Support\Auditable;
use App\Support\OrganizationClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory;
    use Auditable;
    use BelongsToOrganization;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'assigned_user_id', 'created_by_user_id', 'subject_type', 'subject_id',
        'title', 'description', 'priority', 'status',
        'due_at', 'reminder_at', 'completed_at', 'source', 'is_internal',
    ];

    protected array $auditable = ['assigned_user_id', 'status', 'due_at', 'priority'];

    /** Mirrors the column defaults so a newly created model is never null here. */
    protected $attributes = [
        'priority' => 'normal',
        'status' => 'to_do',
        'source' => 'manual',
        'is_internal' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => Priority::class,
            'due_at' => 'datetime',
            'reminder_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_internal' => 'boolean',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && ! $this->status->isClosed()
            && $this->due_at->isPast();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', TaskStatus::openValues());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function scopeDueToday(Builder $query): Builder
    {
        $clock = app(OrganizationClock::class);

        return $query->open()->whereBetween('due_at', [$clock->startOfToday(), $clock->endOfToday()]);
    }

    public function scopeUpcoming(Builder $query, int $days = 7): Builder
    {
        $clock = app(OrganizationClock::class);

        return $query->open()->whereBetween('due_at', [$clock->endOfToday(), $clock->endOfDayIn($days)]);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->open()->whereNull('assigned_user_id');
    }
}
