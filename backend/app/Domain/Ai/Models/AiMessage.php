<?php

namespace App\Domain\Ai\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    use HasUuid;

    protected $fillable = ['conversation_id', 'role', 'content', 'tool_calls', 'tool_results'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'tool_results' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
