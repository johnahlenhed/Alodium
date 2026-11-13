<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * analytics:prune: page views (visits) older than the retention period are
 * deleted with their events; newer ones are kept.
 */
class PruneAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 03:30:00', 'UTC'));
    }

    /** A visit (and $events events) at $at; returns its id. */
    private function visitAt(string $at, int $events = 0): int
    {
        $id = DB::table('visits')->insertGetId(['url' => '/', 'created_at' => Carbon::parse($at, 'UTC')]);
        for ($i = 0; $i < $events; $i++) {
            DB::table('events')->insert(['visit_id' => $id, 'type' => 'outbound_click', 'payload' => '{"url":"https://example.com/"}', 'created_at' => Carbon::parse($at, 'UTC')]);
        }

        return $id;
    }

    public function test_visits_older_than_the_retention_period_are_deleted_with_their_events(): void
    {
        $this->assertSame(90, config('analytics.retention_days'));
        // The cutoff is 90 days before now: 2026-08-18 03:30:00.
        $old = $this->visitAt('2026-05-01 12:00', events: 2);
        $justOutside = $this->visitAt('2026-08-18 03:29:59', events: 1);
        $atCutoff = $this->visitAt('2026-08-18 03:30:00', events: 1);
        $inside = $this->visitAt('2026-08-19 00:00', events: 1);
        $today = $this->visitAt('2026-11-16 01:00');

        $this->artisan('analytics:prune')
            ->expectsOutputToContain('Deleted 2 page view(s) and 3 event(s) older than 90 days (before 2026-08-18 03:30:00).')
            ->assertSuccessful();

        $this->assertSame([$atCutoff, $inside, $today], DB::table('visits')->orderBy('id')->pluck('id')->all());
        $this->assertSame([$atCutoff, $inside], DB::table('events')->orderBy('visit_id')->pluck('visit_id')->all());
        $this->assertNotContains($old, DB::table('events')->pluck('visit_id')->all());
        $this->assertNotContains($justOutside, DB::table('events')->pluck('visit_id')->all());
    }

    public function test_the_period_comes_from_config_or_the_option(): void
    {
        $this->visitAt('2026-11-01 12:00'); // 15 days old
        $this->visitAt('2026-11-10 12:00'); // 6 days old

        config(['analytics.retention_days' => 10]);
        $this->artisan('analytics:prune')->expectsOutputToContain('Deleted 1 page view(s)')->assertSuccessful();
        $this->assertSame(1, DB::table('visits')->count());

        $this->artisan('analytics:prune', ['--days' => 5])->expectsOutputToContain('Deleted 1 page view(s)')->assertSuccessful();
        $this->assertSame(0, DB::table('visits')->count());
    }

    public function test_a_period_below_one_day_is_refused_and_deletes_nothing(): void
    {
        $this->visitAt('2026-11-16 01:00');
        $this->visitAt('2025-01-01 00:00');

        foreach (['0', '-5', 'abc', '1.5'] as $days) {
            $this->artisan('analytics:prune', ['--days' => $days])->assertFailed();
        }
        config(['analytics.retention_days' => 0]);
        $this->artisan('analytics:prune')->assertFailed();

        $this->assertSame(2, DB::table('visits')->count());
    }

    public function test_a_large_backlog_is_deleted_in_batches(): void
    {
        $rows = array_fill(0, 2500, ['url' => '/', 'created_at' => Carbon::parse('2026-01-01', 'UTC')]);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('visits')->insert($chunk);
        }
        $this->visitAt('2026-11-15 12:00');

        $this->artisan('analytics:prune')->expectsOutputToContain('Deleted 2500 page view(s)')->assertSuccessful();
        $this->assertSame(1, DB::table('visits')->count());
    }

    public function test_it_is_scheduled_nightly_with_the_cache_pruning_hourly(): void
    {
        $events = collect(app(Schedule::class)->events());
        $cron = fn (string $command) => $events->first(fn ($event) => str_contains((string) $event->command, $command))?->expression;

        $this->assertSame('30 3 * * *', $cron('analytics:prune'));
        $this->assertSame('0 * * * *', $cron('cache:prune-expired'));
    }
}
