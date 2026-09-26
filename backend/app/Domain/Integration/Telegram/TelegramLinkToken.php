<?php

namespace App\Domain\Integration\Telegram;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An expiring, single-use token that proves a Telegram account belongs to the
 * CRM user who requested the link.
 *
 * Flow: authenticated user requests a token -> receives a t.me deep link ->
 * sends /start TOKEN from their Telegram account -> webhook verifies and links.
 *
 * Only the SHA-256 digest of the token is persisted. The raw token is returned
 * once via the deep link and never stored.
 */
class TelegramLinkToken extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'user_id', 'organization_id', 'token_hash', 'expires_at', 'used_at', 'linked_chat_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isRedeemable(): bool
    {
        return ! $this->isUsed() && ! $this->hasExpired();
    }

    /** Generate a cryptographically random token (raw, never persisted). */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** SHA-256 digest used for storage and lookup. */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** Find a token record by hashing the inbound raw token. */
    public static function findByRawToken(string $rawToken): ?self
    {
        return static::where('token_hash', static::hashToken($rawToken))->first();
    }
}
