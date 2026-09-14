<?php

namespace App\Domain\Ai\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiConversation extends Model
{
    use BelongsToOrganization;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = ['user_id', 'title', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }

    public function actionRequests(): HasMany
    {
        return $this->hasMany(AiActionRequest::class, 'conversation_id');
    }

    /** Only the person who started it, so conversations are never shared. */
    public function belongsToUser(User $user): bool
    {
        return $this->user_id === $user->id;
    }
}
