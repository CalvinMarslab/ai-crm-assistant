<?php

namespace App\Domain\Company\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Opportunity\Models\Opportunity;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory;
    use Auditable;
    use BelongsToOrganization;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'name', 'registration_no', 'industry', 'website',
        'phone', 'email', 'address', 'notes',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function primaryContact(): HasMany
    {
        return $this->hasMany(Contact::class)->where('is_primary', true);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * The unified company timeline: events on the company itself plus every
     * event on its opportunities (PRD section 9).
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
