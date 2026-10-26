<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pins the exact output of /analytics, so moving its queries into a shared
 * service (used by the dashboard too) provably changes nothing.
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
                ->where('topSources', self::same([['label' => 'google', 'count' => 1]]))
                ->where('topReferrers', self::same([['label' => 'https://news.example.com/x', 'count' => 1], ['label' => 'https://www.google.com/', 'count' => 1]]))
                ->where('deviceBreakdown', self::same([['label' => 'desktop', 'count' => 2], ['label' => 'mobile', 'count' => 1]]))
                ->where('topCountries', self::same([['label' => 'SE', 'count' => 2], ['label' => 'US', 'count' => 1]])));
    }

    public function test_default_period_is_30_days_and_clamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/analytics')->assertInertia(fn ($page) => $page->where('days', 30)->has('visitsOverTime', 30));
        $this->actingAs($user)->get('/analytics?days=9999')->assertInertia(fn ($page) => $page->where('days', 365));
        $this->actingAs($user)->get('/analytics?days=-5')->assertInertia(fn ($page) => $page->where('days', 1));
    }
}
