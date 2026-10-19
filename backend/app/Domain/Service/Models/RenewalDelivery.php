<?php

namespace App\Domain\Service\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenewalDelivery extends Model
{
    use BelongsToOrganization;
    use HasUuid;

    protected $fillable = ['service_subscription_id', 'cycle_id', 'audience', 'channel', 'recipient', 'status', 'sent_at', 'error', 'attempts'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class, 'service_subscription_id');
    }
}
