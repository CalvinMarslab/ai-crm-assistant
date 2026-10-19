<!doctype html>
<html lang="en"><body style="font-family:Arial,sans-serif;color:#1e293b;line-height:1.6;max-width:600px;margin:32px auto;padding:24px">
<h1 style="font-size:22px">{{ $internal ? 'Renewal payment to collect' : 'Your service is due for renewal' }}</h1>
<p>{{ $internal ? 'Please follow up with '.$companyName.' to collect the renewal payment.' : 'Dear '.$companyName.',' }}</p>
<p>The following service expires on <strong>{{ $expiresOn }}</strong>.</p>
<table cellpadding="8"><tr><th align="left">Service</th><td>{{ $serviceName }}</td></tr><tr><th align="left">Type</th><td>{{ ucfirst($serviceType) }}</td></tr><tr><th align="left">Renewal amount</th><td>{{ $currency }} {{ number_format((float) $amount, 2) }}</td></tr></table>
<p>{{ $internal ? 'After confirming receipt, record the payment in CRM to update the next expiry date.' : 'Please reply to this Email to arrange renewal and payment. If payment has already been made, please send us the payment reference so we can update our records.' }}</p>
<p>Thank you,<br>Techweiser Support</p>
</body></html>
