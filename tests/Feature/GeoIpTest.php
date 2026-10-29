<?php

namespace Tests\Feature;

use App\Models\Visit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * Visitor countries from the local GeoIP database, with the real package and
 * MaxMind's test database (tests/Fixtures/geoip: 89.160.20.112 is SE,
 * 2001:218::1 is JP), and the monthly update in the scheduler.
 */
class GeoIpTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/geoip/GeoIP2-Country-Test.mmdb';

    private function useDatabase(?string $path): void
    {
        config(['location.maxmind.local.path' => $path ?? sys_get_temp_dir().'/alodium-no-geoip-'.uniqid().'.mmdb']);
    }

    /** A tracker request from $ip; returns the stored country. */
    private function visitFrom(string $ip): ?string
    {
        $id = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/collect', ['url' => '/'])
            ->assertOk()
            ->json('visit_id');

        return Visit::findOrFail($id)->country;
    }

    public function test_a_visit_gets_the_country_of_its_ip(): void
    {
        $this->useDatabase(self::FIXTURE);

        $this->assertSame('SE', $this->visitFrom('89.160.20.112'));
        $this->assertSame('JP', $this->visitFrom('2001:218::1'));
        // Local and unknown addresses aren't in the database: no country, still recorded.
        $this->assertNull($this->visitFrom('127.0.0.1'));
        $this->assertNull($this->visitFrom('203.0.113.9'));
        $this->assertSame(4, Visit::count());
    }

    public function test_without_the_database_visits_are_recorded_without_a_country(): void
    {
        $this->useDatabase(null);

        $this->assertNull($this->visitFrom('89.160.20.112'));
        $this->assertSame(1, Visit::count());
    }

    public function test_once_the_database_is_installed_the_same_visitor_gets_a_country(): void
    {
        // The missing-file result (null) is not cached for a day.
        $this->useDatabase(null);
        $this->assertNull($this->visitFrom('89.160.20.112'));

        $this->useDatabase(self::FIXTURE);
        $this->assertSame('SE', $this->visitFrom('89.160.20.112'));
    }

    public function test_no_result_from_the_package_is_handled_explicitly(): void
    {
        // Location::get() returns false, not null, when it has no answer.
        Location::shouldReceive('get')->once()->with('89.160.20.112')->andReturn(false);

        $this->assertNull($this->visitFrom('89.160.20.112'));
    }

    public function test_the_database_is_updated_monthly_when_a_license_key_is_set(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'location:update'));

        $this->assertNotNull($event, 'location:update is scheduled');
        $this->assertSame('0 3 1 * *', $event->expression);

        config(['location.maxmind.license_key' => null]);
        $this->assertFalse($event->filtersPass($this->app), 'skipped without a key (the download would fail)');
        config(['location.maxmind.license_key' => 'a-key']);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
