{{ $internal ? 'Renewal payment to collect' : 'Your service is due for renewal' }}

Company: {{ $companyName }}
Service: {{ $serviceName }}
Type: {{ $serviceType }}
Expiry: {{ $expiresOn }}
Renewal amount: {{ $currency }} {{ number_format((float) $amount, 2) }}

{{ $internal ? 'Please follow up to collect payment, then record the payment in CRM.' : 'Please reply to arrange renewal and payment. If you have already paid, please send your payment reference.' }}

Thank you,
Techweiser Support
