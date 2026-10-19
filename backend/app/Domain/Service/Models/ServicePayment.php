<?php

namespace App\Domain\Service\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;

class ServicePayment extends Model
{
    use Auditable;
    use BelongsToOrganization;
    use HasUuid;

    protected $fillable = ['service_subscription_id', 'cycle_id', 'amount', 'currency', 'paid_on', 'previous_expires_on', 'next_expires_on', 'reference', 'recorded_by_user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'previous_expires_on' => 'date', 'next_expires_on' => 'date'];
    }
}
