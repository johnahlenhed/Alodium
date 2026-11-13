<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes expired rows from the database cache store. Laravel's database store
 * only removes an expired row when the same key is read again, so a key that is
 * never read again (the GeoIP lookup of a one-time visitor, a rate-limit counter
 * keyed on an IP hash) would otherwise stay in the table for good. Scheduled
 * hourly (routes/console.php). Expired rows are already invisible to the app, so
 * deleting them changes nothing else. Other stores need nothing: Redis and
 * Memcached expire keys themselves, and the array store only lives for one request.
 */
class PruneExpiredCache extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete expired rows from the database cache store (Laravel only removes one when it is read again)';

    public function handle(): int
    {
        $name = (string) config('cache.default');
        $store = (array) config("cache.stores.{$name}");

        if (($store['driver'] ?? null) !== 'database') {
            $this->line("The cache store \"{$name}\" isn't the database store, so there is nothing to prune.");

            return self::SUCCESS;
        }

        // As DatabaseStore: a row is valid while its expiration is after now.
        $deleted = DB::connection($store['connection'] ?? null)
            ->table($store['table'] ?? 'cache')
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();

        $this->info("Deleted {$deleted} expired cache row(s).");

        return self::SUCCESS;
    }
}
