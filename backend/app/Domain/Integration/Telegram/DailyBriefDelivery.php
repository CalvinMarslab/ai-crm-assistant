<?php

namespace App\Domain\Integration\Telegram;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent per-user/local-date delivery ledger.
 *
 * Ensures each user receives at most one daily brief per local date, even if
 * the scheduler reruns or the command is retried after a partial failure.
 *
 * Statuses:
 *   queued    — scheduled, not yet attempted (retryable)
 *   sending   — claimed for the HTTP call (at-most-once guard)
 *   delivered — Telegram confirmed receipt (terminal)
 *   failed    — confirmed transport failure (retryable)
 *   uncertain — retry found prior 'sending' state, crash window (terminal, non-retryable)
 */
class DailyBriefDelivery extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'user_id', 'organization_id', 'delivery_date', 'status',
        'delivered_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'delivered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSending(): bool
    {
        return $this->status === 'sending';
    }

    /** Crash-window state: the prior attempt was in 'sending' when a retry arrived. Terminal. */
    public function isUncertain(): bool
    {
        return $this->status === 'uncertain';
    }

    /** Whether this delivery is in a terminal state that must never be retried. */
    public function isTerminal(): bool
    {
        return $this->isDelivered() || $this->isSending() || $this->isUncertain();
    }
}
