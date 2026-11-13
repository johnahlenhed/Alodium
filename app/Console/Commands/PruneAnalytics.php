<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes page views (visits) older than the retention period, with their
 * events, so analytics data isn't kept indefinitely. Scheduled nightly
 * (routes/console.php); config/analytics.php (ANALYTICS_RETENTION_DAYS, 90 by
 * default) sets the period. Events are deleted explicitly before their visits
 * rather than left to the foreign key's cascade, so it works the same on every
 * database. In batches, so a large backlog never locks the table for long.
 */
class PruneAnalytics extends Command
{
    protected $signature = 'analytics:prune
        {--days= : Keep this many days instead of config analytics.retention_days}';

    protected $description = 'Delete page views (visits and their events) older than the retention period';

    private const BATCH = 1000;

    public function handle(): int
    {
        $days = $this->option('days') ?? config('analytics.retention_days');
        if (! is_numeric($days) || (int) $days != $days || (int) $days < 1) {
            $this->error('The retention period must be a whole number of days, at least 1 (got '.var_export($days, true).').');

            return self::FAILURE;
        }
        $days = (int) $days;
        $cutoff = now()->subDays($days);

        $visits = 0;
        $events = 0;
        do {
            $ids = DB::table('visits')->where('created_at', '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            DB::transaction(function () use ($ids, &$visits, &$events) {
                $events += DB::table('events')->whereIn('visit_id', $ids)->delete();
                $visits += DB::table('visits')->whereIn('id', $ids)->delete();
            });
        } while ($ids->count() === self::BATCH);

        if ($visits > 0) {
            Log::info('Old analytics data deleted.', ['visits' => $visits, 'events' => $events, 'older_than' => $cutoff->toDateTimeString()]);
        }
        $this->info("Deleted {$visits} page view(s) and {$events} event(s) older than {$days} days (before {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
