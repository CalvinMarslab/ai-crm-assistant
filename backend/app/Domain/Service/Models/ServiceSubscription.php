<?php

namespace App\Domain\Service\Models;

use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Support\Auditable;
use App\Support\OrganizationClock;
use Illuminate\Database\Eloquent\Builder;
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

    protected $fillable = ['company_id', 'owner_user_id', 'name', 'type', 'customer_contact_name', 'customer_phone', 'customer_email', 'internal_email', 'amount', 'currency', 'renewal_months', 'expires_on', 'status', 'notes', 'cycle_id', 'collection_task_id'];

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

    public function missingRenewalDetails(): array
    {
        return array_values(array_filter(['customer_email', 'amount', 'currency', 'renewal_months'], fn (string $field): bool => $this->{$field} === null || $this->{$field} === ''));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canDo(PermissionCode::ServiceView)) {
            return $query;
        }

        $types = [];
        if ($user->canDo(PermissionCode::ServiceViewServer)) {
            $types[] = 'server';
        }
        if ($user->canDo(PermissionCode::ServiceViewMaintenance)) {
            $types[] = 'maintenance';
        }

        return $query->whereIn('type', $types);
    }

    public function hasRenewalTerms(): bool
    {
        return filled($this->currency) && $this->renewal_months > 0;
    }

    public function canRecordPayment(): bool
    {
        return $this->status === 'active' && $this->hasRenewalTerms() && $this->amount !== null;
    }

    public function canNotifyCustomer(): bool
    {
        return $this->canRecordPayment() && filled($this->customer_email);
    }

    public function renewalAmountLabel(): string
    {
        return $this->amount === null ? 'Price pending' : "{$this->currency} {$this->amount}";
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
