<?php

namespace App\Domain\Notification\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification centre (Phase 1). Named app_notifications so Laravel's
 * own notifications table stays free for later channels such as Telegram.
 */
class AppNotification extends Model
{
    use BelongsToOrganization;
    use HasUuid;

    protected $table = 'app_notifications';

    protected $fillable = ['organization_id', 'user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
