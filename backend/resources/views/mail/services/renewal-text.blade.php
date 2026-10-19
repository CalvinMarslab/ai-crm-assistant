{{ $internal ? ($amount === null ? 'Renewal price to review' : 'Renewal payment to collect') : 'Your service is due for renewal' }}

Company: {{ $companyName }}
Service: {{ $serviceName }}
Type: {{ $serviceType }}
Expiry: {{ $expiresOn }}
Renewal amount: {{ $amount === null ? 'Price pending' : $currency.' '.number_format((float) $amount, 2) }}

{{ $internal ? ($amount === null ? 'Please add the renewal price in CRM before requesting payment. This reminder is internal only. Customer reminders require both a customer email and a price.' : 'Please follow up to collect payment, then record the payment in CRM.') : 'Please reply to arrange renewal and payment. If you have already paid, please send your payment reference.' }}

Thank you,
Techweiser Support
