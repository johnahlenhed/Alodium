<?php

namespace Tests\Feature;

use App\Support\GeoIpCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

/**
 * The GeoIP lookup cache (App\Support\GeoIpCache, TrackingController::countryFor())
 * and cache:prune-expired: the IP is never stored, nor anything it can be recovered
 * from without the secret, and nothing outlives its UTC day.
 */
class GeoIpCacheTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-geoip-cache-secret-0123456789abcdef';

    private const IP = '203.0.113.77';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.geoip.cache_salt' => self::SECRET]);
        $position = new Position;
        $position->countryCode = 'SE';
        Location::shouldReceive('get')->andReturn($position);
    }

    /** One page view from $ip, with the database cache store production uses (.env.example). */
    private function visitFrom(string $ip): void
    {
        config(['cache.default' => 'database']);
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/collect', ['url' => '/'])->assertOk();
    }

    /** The geo rows in the cache table: [key without the store prefix => expiration]. */
    private function geoRows(): array
    {
        return DB::table('cache')->where('key', 'like', '%geo:%')->get()
            ->mapWithKeys(fn ($row) => [substr($row->key, strpos($row->key, 'geo:')) => (int) $row->expiration])->all();
    }

    public function test_the_same_ip_gets_a_different_key_on_another_day(): void
    {
        $monday = CarbonImmutable::parse('2026-11-16 09:00', 'UTC');
        $tuesday = $monday->addDay();

        $this->assertNotSame(GeoIpCache::key(self::IP, $monday), GeoIpCache::key(self::IP, $tuesday));
        // The same all day long (UTC), and a different IP gets a different key.
        $this->assertSame(GeoIpCache::key(self::IP, $monday), GeoIpCache::key(self::IP, $monday->setTime(23, 59, 59)));
        $this->assertSame(GeoIpCache::key(self::IP, $monday), GeoIpCache::key(self::IP, CarbonImmutable::parse('2026-11-16 23:30', 'America/New_York')->subDay()->addHours(5)));
        $this->assertNotSame(GeoIpCache::key(self::IP, $monday), GeoIpCache::key('203.0.113.78', $monday));
        $this->assertMatchesRegularExpression('/^geo:[0-9a-f]{64}$/', GeoIpCache::key(self::IP, $monday));

        // Through the endpoint: two days, two rows, neither holding the IP or a plain hash of it.
        $this->travelTo($monday);
        $this->visitFrom(self::IP);
        $this->travelTo($tuesday);
        $this->visitFrom(self::IP);
        $keys = array_keys($this->geoRows());
        $this->assertSame([GeoIpCache::key(self::IP, $monday), GeoIpCache::key(self::IP, $tuesday)], $keys);
        $stored = json_encode(DB::table('cache')->get());
        foreach ([self::IP, sha1(self::IP), hash('sha256', self::IP), md5(self::IP)] as $form) {
            $this->assertStringNotContainsString($form, $stored);
        }
    }

    public function test_a_stored_key_cannot_be_turned_back_into_the_ip_by_hashing_candidate_addresses(): void
    {
        $day = CarbonImmutable::parse('2026-11-16 09:00', 'UTC');
        $this->travelTo($day);
        $this->visitFrom(self::IP);
        $stored = substr(array_key_first($this->geoRows()), strlen('geo:'));

        // What someone with the database (a dump, a backup) but not .env could try. The
        // whole IPv4 space is 2^32 addresses, too many for a test; this /16 holds the real
        // address, so a search of the whole space would find no more than this one does.
        $attempts = [
            'sha1(ip), the old key' => fn ($ip) => sha1($ip),
            'sha256(ip)' => fn ($ip) => hash('sha256', $ip),
            'md5(ip)' => fn ($ip) => md5($ip),
            'sha256(date|ip)' => fn ($ip) => hash('sha256', $day->toDateString().'|'.$ip),
            'hmac with an empty key' => fn ($ip) => hash_hmac('sha256', $day->toDateString().'|'.$ip, ''),
            'hmac with APP_KEY' => fn ($ip) => hash_hmac('sha256', $day->toDateString().'|'.$ip, (string) config('app.key')),
            "hmac with the secret but yesterday's date" => fn ($ip) => hash_hmac('sha256', $day->subDay()->toDateString().'|'.$ip, self::SECRET),
        ];
        $search = function (callable $hash) use ($stored): ?string {
            for ($a = 0; $a < 256; $a++) {
                for ($b = 0; $b < 256; $b++) {
                    if ($hash("203.0.{$a}.{$b}") === $stored) {
                        return "203.0.{$a}.{$b}";
                    }
                }
            }

            return null;
        };
        foreach ($attempts as $name => $hash) {
            $this->assertNull($search($hash), "Recovered with {$name}");
        }

        // The control: with the secret and the day, the same search does find it.
        $this->assertSame(self::IP, $search(fn ($ip) => hash_hmac('sha256', $day->toDateString().'|'.$ip, self::SECRET)));
    }

    public function test_an_entry_expires_at_the_end_of_its_utc_day_and_is_pruned_after(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 22:15', 'UTC'));
        $this->visitFrom(self::IP);
        // Valid until the day ends: midnight UTC (Laravel rounds 23:59:59.999 up to the second).
        $this->assertSame([GeoIpCache::key(self::IP) => CarbonImmutable::parse('2026-11-17 00:00:00', 'UTC')->getTimestamp()], $this->geoRows());

        // Still valid within the day: pruning leaves it.
        $this->artisan('cache:prune-expired')->assertSuccessful();
        $this->assertCount(1, $this->geoRows());

        // The next day, a visitor who never comes back: no read deletes the row, the command does.
        $this->travelTo(CarbonImmutable::parse('2026-11-17 01:00', 'UTC'));
        $this->assertCount(1, $this->geoRows(), 'Laravel left the expired row in the table');
        $this->artisan('cache:prune-expired')->expectsOutputToContain('Deleted 1 expired cache row(s).')->assertSuccessful();
        $this->assertSame([], $this->geoRows());
    }

    public function test_pruning_deletes_every_expired_row_and_keeps_the_rest(): void
    {
        config(['cache.default' => 'database']);
        $now = now()->getTimestamp();
        DB::table('cache')->insert([
            ['key' => 'alodium-cache-geo:old', 'value' => 's:2:"SE";', 'expiration' => $now - 3600],
            ['key' => 'alodium-cache-'.sha1('|203.0.113.9'), 'value' => 'i:3;', 'expiration' => $now - 60], // a rate-limit counter
            ['key' => 'alodium-cache-just-expired', 'value' => 'b:1;', 'expiration' => $now],
            ['key' => 'alodium-cache-geo:today', 'value' => 's:2:"SE";', 'expiration' => $now + 3600],
            ['key' => 'alodium-cache-forever', 'value' => 'b:1;', 'expiration' => $now + 315360000],
        ]);

        $this->artisan('cache:prune-expired')->expectsOutputToContain('Deleted 3 expired cache row(s).')->assertSuccessful();

        $this->assertSame(['alodium-cache-forever', 'alodium-cache-geo:today'], DB::table('cache')->orderBy('key')->pluck('key')->all());
    }

    public function test_pruning_does_nothing_for_a_store_that_expires_keys_itself(): void
    {
        config(['cache.default' => 'array']);
        DB::table('cache')->insert(['key' => 'untouched', 'value' => 'b:1;', 'expiration' => now()->getTimestamp() - 60]);

        $this->artisan('cache:prune-expired')->expectsOutputToContain("isn't the database store")->assertSuccessful();
        $this->assertSame(1, DB::table('cache')->count());
    }

    public function test_without_the_secret_the_country_is_looked_up_but_nothing_is_cached(): void
    {
        config(['services.geoip.cache_salt' => null]);
        $this->assertNull(GeoIpCache::key(self::IP));

        $this->visitFrom(self::IP);

        $this->assertSame('SE', DB::table('visits')->value('country'));
        $this->assertSame([], $this->geoRows());
    }
}
