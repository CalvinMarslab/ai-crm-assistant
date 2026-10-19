<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly, because each organization gets the brief at its own local hour.
Schedule::command('crm:daily-brief')->hourly()->withoutOverlapping();
Schedule::command('crm:renewal-reminders')->hourly()->withoutOverlapping();
