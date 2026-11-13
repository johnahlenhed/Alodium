<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The cache key for a visitor's GeoIP country lookup (TrackingController), and
 * how long it may live.
 *
 * The key is an HMAC-SHA256 of the IP keyed with GEOIP_CACHE_SALT, a secret of
 * its own (not APP_KEY), with the UTC date in its input: the same IP gets a new
 * key every day, so yesterday's keys can't be matched to today's, and without
 * the secret a key can't be turned back into the IP by hashing candidate
 * addresses (an unkeyed hash of an IPv4 address can be, in minutes). Whoever has
 * the secret (it is in .env) could still recompute one day's keys, so the keys
 * protect a leaked database or backup, not a fully compromised server. The rows
 * expire at the end of their UTC day and cache:prune-expired deletes them within
 * the hour (the database cache store only removes an expired row when it is read
 * again).
 *
 * Without a secret there is no key: the country is looked up without caching,
 * never cached under a hash anyone could reverse.
 */
final class GeoIpCache
{
    public static function key(string $ip, ?CarbonInterface $now = null): ?string
    {
        $secret = config('services.geoip.cache_salt');
        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return 'geo:'.hash_hmac('sha256', self::day($now)->toDateString().'|'.$ip, $secret);
    }

    /** The end of the key's UTC day: a key never outlives the date in it. */
    public static function expiresAt(?CarbonInterface $now = null): CarbonImmutable
    {
        return self::day($now)->endOfDay();
    }

    private static function day(?CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();
    }
}
