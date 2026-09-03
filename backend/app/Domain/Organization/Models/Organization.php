<?php

namespace App\Domain\Organization\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class Organization extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = ['name', 'status', 'timezone', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Configurable rather than hard-coded (CLAUDE.md architecture rule 6).
     */
    public function inactivityThresholdDays(): int
    {
        return (int) ($this->settings['inactivity_threshold_days']
            ?? config('crm.inactivity_threshold_days'));
    }
}
