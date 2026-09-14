<?php

namespace App\Domain\Ai\Models;

use App\Domain\Ai\Enums\ActionRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change the assistant proposed and the user has not yet agreed to.
 *
 * Nothing is written when the assistant asks; the request is recorded here and
 * only becomes real if the user confirms it, which is what "write actions
 * require confirmation" means in practice.
 */
class AiActionRequest extends Model
{
    use Auditable;
    use BelongsToOrganization;
    use HasUuid;

    protected $fillable = [
        'organization_id', 'user_id', 'conversation_id', 'action_name', 'action_payload',
        'summary', 'status', 'confirmation_required', 'confirmed_at', 'executed_at',
        'expires_at', 'execution_result',
    ];

    protected array $auditable = ['status', 'confirmed_at', 'executed_at'];

    protected $attributes = [
        'status' => 'pending',
        'confirmation_required' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => ActionRequestStatus::class,
            'action_payload' => 'array',
            'execution_result' => 'array',
            'confirmation_required' => 'boolean',
            'confirmed_at' => 'datetime',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /** A stale proposal is not a standing permission to act. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActionable(): bool
    {
        return $this->status->isOpen() && ! $this->hasExpired();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ActionRequestStatus::Pending->value);
    }
}
