<?php

namespace App\Domain\Agent\Models;

use App\Domain\Opportunity\Models\Opportunity;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use HasFactory;
    use Auditable;
    use BelongsToOrganization;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'company_name', 'email',
        'phone', 'status', 'notes', 'joined_at',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'date'];
    }

    /** Portal login, linked in Phase 2. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'referral_agent_id');
    }
}
