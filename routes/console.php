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
