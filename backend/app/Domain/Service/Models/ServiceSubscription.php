<?php

namespace App\Domain\Service\Models;

use App\Domain\Company\Models\Company;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Support\Auditable;
use App\Support\OrganizationClock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceSubscription extends Model
{
    use Auditable;
    use BelongsToOrganization;
    use HasFactory;
    use HasUuid;

    protected $fillable = ['company_id', 'owner_user_id', 'name', 'type', 'customer_email', 'internal_email', 'amount', 'currency', 'renewal_months', 'expires_on', 'status', 'notes', 'cycle_id', 'collection_task_id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'amount' => 'decimal:2', 'renewal_months' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ServicePayment::class)->orderByDesc('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(RenewalDelivery::class)->orderByDesc('id');
    }

    public function renewalStatus(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }
        $clock = app(OrganizationClock::class);
        if ($this->expires_on->toDateString() < $clock->now()->toDateString()) {
            return 'overdue';
        }

        return $this->expires_on->toDateString() <= $clock->now()->addDays(15)->toDateString() ? 'expiring' : 'active';
    }
}
