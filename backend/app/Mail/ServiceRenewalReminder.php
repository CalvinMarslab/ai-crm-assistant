<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ServiceRenewalReminder extends Mailable
{
    public function __construct(
        public readonly string $serviceName,
        public readonly string $companyName,
        public readonly string $serviceType,
        public readonly string $expiresOn,
        public readonly string $amount,
        public readonly string $currency,
        public readonly bool $internal,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->internal ? 'Collection reminder: ' : 'Service renewal reminder: ').$this->serviceName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.services.renewal', text: 'mail.services.renewal-text');
    }
}
