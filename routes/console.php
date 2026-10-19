<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Publishes scheduled posts and pages. Needs the server cron:
// * * * * * cd /var/www/alodium && php artisan schedule:run
Schedule::command('content:publish-scheduled')->everyMinute()->withoutOverlapping();
