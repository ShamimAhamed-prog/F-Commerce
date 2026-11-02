<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull courier status for unfinished parcels (requires the cron entry for schedule:run, see docs/DEPLOYMENT.md).
Illuminate\Support\Facades\Schedule::command('fc:courier-sync')->everyFifteenMinutes()->withoutOverlapping();
