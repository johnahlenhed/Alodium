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

// Daily database backup to storage/app/backups (keeps the newest 30).
Schedule::command('db:backup')->dailyAt('02:00')->withoutOverlapping();

// Copies new and changed media to the off-server bucket (only when BACKUP_S3_* is set).
Schedule::command('media:backup-remote')->dailyAt('02:30')->withoutOverlapping();

// Refreshes the GeoIP country database (storage/app/geoip, README deployment step 6)
// on the 1st of each month. Only with MAXMIND_LICENSE_KEY set: without a key the
// download fails. The dashboard's Site status warns when the file is missing or old.
Schedule::command('location:update')->monthlyOn(1, '03:00')->withoutOverlapping()
    ->when(fn () => filled(config('location.maxmind.license_key')));
