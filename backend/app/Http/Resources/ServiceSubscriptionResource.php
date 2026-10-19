<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid, 'cycle_id' => $this->cycle_id,
            'company' => new CompanySummaryResource($this->whenLoaded('company')),
            'owner' => new UserSummaryResource($this->whenLoaded('owner')),
            'name' => $this->name, 'type' => $this->type,
            'customer_email' => $this->customer_email, 'internal_email' => $this->internal_email,
            'amount' => $this->amount, 'currency' => $this->currency,
            'renewal_months' => $this->renewal_months, 'expires_on' => $this->expires_on->toDateString(),
            'status' => $this->status, 'renewal_status' => $this->renewalStatus(), 'notes' => $this->notes,
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->uuid, 'amount' => $payment->amount, 'currency' => $payment->currency,
                'paid_on' => $payment->paid_on->toDateString(), 'reference' => $payment->reference,
                'previous_expires_on' => $payment->previous_expires_on->toDateString(), 'next_expires_on' => $payment->next_expires_on->toDateString(),
            ])),
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($delivery) => [
                'id' => $delivery->uuid, 'cycle_id' => $delivery->cycle_id, 'channel' => $delivery->channel,
                'audience' => $delivery->audience, 'recipient' => $delivery->recipient, 'status' => $delivery->status,
                'sent_at' => $delivery->sent_at?->toIso8601String(), 'error' => $delivery->error,
            ])),
        ];
    }
}
