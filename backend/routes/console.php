<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule stale import recovery every 10 minutes without overlapping
Schedule::command('import:recover-stale')->everyTenMinutes()->withoutOverlapping();
