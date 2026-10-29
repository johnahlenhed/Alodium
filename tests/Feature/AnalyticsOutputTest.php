<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pins the exact output of /analytics, so moving its queries into a shared
 * service (used by the dashboard too) provably changes nothing. One deliberate
 * change since: Traffic sources and Top referrers use TrafficSource, like the
 * dashboard (a source is the UTM source, else the referring host, else Direct).
 */
class AnalyticsOutputTest extends TestCase
{
    use RefreshDatabase;

    private function visit(string $at, array $attributes = []): void
    {
        $visit = new Visit(['url' => '/', ...$attributes]);
        $visit->created_at = Carbon::parse($at, 'UTC');
        $visit->save();
    }

    /** Rows with equal counts come back in no fixed order (existing behaviour), so compare them sorted. */
    private static function same(array $expected): \Closure
    {
        $sort = fn ($rows) => collect($rows)->sortBy([['count', 'desc'], ['label', 'asc']])->values()->all();

        return fn ($actual) => $sort($actual) === $sort($expected)
            && collect($actual)->pluck('count')->all() === collect($expected)->pluck('count')->sortDesc()->values()->all();
    }

    public function test_analytics_output_is_exactly_as_before(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->visit('2026-10-22 09:00', ['url' => '/blog/a/', 'utm_source' => 'google', 'referrer' => 'https://www.google.com/', 'device_type' => 'mobile', 'country' => 'SE', 'duration' => 30, 'scroll_depth' => 50]);
        $this->visit('2026-10-22 10:00', ['url' => '/blog/a/', 'referrer' => 'https://news.example.com/x', 'device_type' => 'desktop', 'country' => 'SE', 'duration' => 91, 'scroll_depth' => 100]);
        $this->visit('2026-10-20 10:00', ['url' => '/about/', 'device_type' => 'desktop', 'country' => 'US']);
        $this->visit('2026-10-16 23:59', ['url' => '/old/']); // just inside a 7-day window
        $this->visit('2026-10-15 23:59', ['url' => '/older/']); // just outside it

        $this->actingAs(User::factory()->create())->get('/analytics?days=7')
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Index')
                ->where('totalVisits', 4)
                ->where('avgDuration', 61)       // round((30 + 91) / 2)
                ->where('avgScrollDepth', 75)
                ->where('days', 7)
                ->where('visitsOverTime', [
                    ['date' => '2026-10-16', 'count' => 1],
                    ['date' => '2026-10-17', 'count' => 0],
                    ['date' => '2026-10-18', 'count' => 0],
                    ['date' => '2026-10-19', 'count' => 0],
                    ['date' => '2026-10-20', 'count' => 1],
                    ['date' => '2026-10-21', 'count' => 0],
                    ['date' => '2026-10-22', 'count' => 2],
                ])
                ->where('topPages', self::same([['label' => '/blog/a/', 'count' => 2], ['label' => '/about/', 'count' => 1], ['label' => '/old/', 'count' => 1]]))
                // Was UTM-only ([google: 1]); now every visit has a source, as on the dashboard.
                ->where('topSources', [['label' => 'Direct', 'count' => 2], ['label' => 'google', 'count' => 1], ['label' => 'news.example.com', 'count' => 1]])
                ->where('topReferrers', self::same([['label' => 'https://news.example.com/x', 'count' => 1], ['label' => 'https://www.google.com/', 'count' => 1]]))
                ->where('deviceBreakdown', self::same([['label' => 'desktop', 'count' => 2], ['label' => 'mobile', 'count' => 1]]))
                ->where('topCountries', self::same([['label' => 'SE', 'count' => 2], ['label' => 'US', 'count' => 1]])));
    }

    public function test_navigation_within_the_site_is_not_a_referrer(): void
    {
        config(['app.public_site_url' => 'https://example.com']);
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->visit('2026-10-22 09:00', ['referrer' => 'https://example.com/']);
        $this->visit('2026-10-22 09:01', ['referrer' => 'https://www.example.com/blog/a-post/']); // www. is the same site
        $this->visit('2026-10-22 09:02', ['referrer' => 'https://example.com:443/about']);
        $this->visit('2026-10-22 09:03', ['referrer' => 'https://shop.example.com/']); // another host
        $this->visit('2026-10-22 09:04', ['referrer' => 'https://www.google.com/', 'utm_source' => 'newsletter']);

        $this->actingAs(User::factory()->create())->get('/analytics?days=7')->assertInertia(fn ($page) => $page
            ->where('topReferrers', [
                ['label' => 'https://shop.example.com/', 'count' => 1],
                ['label' => 'https://www.google.com/', 'count' => 1],
            ])
            ->where('topSources', [
                ['label' => 'Your own site', 'count' => 3],
                ['label' => 'newsletter', 'count' => 1],
                ['label' => 'shop.example.com', 'count' => 1],
            ]));
    }

    public function test_without_public_site_url_no_referrer_is_left_out(): void
    {
        config(['app.public_site_url' => null]);
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->visit('2026-10-22 09:00', ['referrer' => 'http://localhost:4321/']);

        $this->actingAs(User::factory()->create())->get('/analytics?days=7')->assertInertia(fn ($page) => $page
            ->where('topReferrers', [['label' => 'http://localhost:4321/', 'count' => 1]])
            ->where('topSources', [['label' => 'localhost', 'count' => 1]]));
    }

    public function test_analytics_and_dashboard_show_the_same_traffic_sources(): void
    {
        config(['app.public_site_url' => 'https://example.com']);
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        foreach ([
            ['referrer' => 'https://www.google.com/search?q=x'], ['referrer' => 'https://google.com/'],
            ['referrer' => 'https://example.com/'], ['utm_source' => 'newsletter'], [],
            ['referrer' => 'https://news.example.org/a', 'utm_source' => ' '], // blank UTM: the referrer counts
        ] as $i => $attributes) {
            $this->visit("2026-10-21 10:0{$i}", $attributes);
        }
        $admin = User::factory()->create(['role' => 'admin']);

        $analytics = $this->actingAs($admin)->get('/analytics?days=7')->viewData('page')['props']['topSources'];
        $dashboard = $this->actingAs($admin)->get('/dashboard?days=7')->viewData('page')['props']['sources'];

        $this->assertSame([
            ['label' => 'google.com', 'count' => 2],
            // Equal counts by label, byte order (as on the dashboard): capitals first.
            ['label' => 'Direct', 'count' => 1],
            ['label' => 'Your own site', 'count' => 1],
            ['label' => 'news.example.org', 'count' => 1],
            ['label' => 'newsletter', 'count' => 1],
        ], $analytics);
        $this->assertSame($analytics, $dashboard); // the dashboard shows the top 5, here all of them
    }

    public function test_default_period_is_30_days_and_clamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/analytics')->assertInertia(fn ($page) => $page->where('days', 30)->has('visitsOverTime', 30));
        $this->actingAs($user)->get('/analytics?days=9999')->assertInertia(fn ($page) => $page->where('days', 365));
        $this->actingAs($user)->get('/analytics?days=-5')->assertInertia(fn ($page) => $page->where('days', 1));
    }
}
