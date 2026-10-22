<?php

namespace App\Services;

use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated page-view statistics, shared by /analytics and the dashboard.
 * Only aggregate queries: visits are never loaded into PHP one by one.
 * Days are calendar days in the app timezone (config app.timezone, UTC).
 */
class VisitStatistics
{
    /** Start of a period of $days days ending today (today included). */
    public static function periodStart(int $days): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays($days - 1);
    }

    /** Visits from $since (inclusive) until $until (exclusive), or without an end. */
    public function visits(CarbonImmutable $since, ?CarbonImmutable $until = null): Builder
    {
        return Visit::where('created_at', '>=', $since)
            ->when($until, fn (Builder $query) => $query->where('created_at', '<', $until));
    }

    public function count(CarbonImmutable $since, ?CarbonImmutable $until = null): int
    {
        return $this->visits($since, $until)->count();
    }

    /** Rounded average of a column, ignoring NULLs; 0 when there is nothing to average. */
    public function average(string $column, CarbonImmutable $since): int
    {
        return (int) round($this->visits($since)->whereNotNull($column)->avg($column) ?? 0);
    }

    /**
     * Daily counts with zero-filled gaps, so the line chart shows quiet days as 0
     * instead of drawing a straight line across missing dates.
     */
    public function daily(CarbonImmutable $since): array
    {
        $counts = $this->visits($since)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->pluck('count', 'date');

        $series = [];
        for ($day = $since; $day->lte(CarbonImmutable::today()); $day = $day->addDay()) {
            $key = $day->toDateString();
            $series[] = ['date' => $key, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $series;
    }

    /** Top values of a column by visit count, ignoring NULLs. */
    public function topBy(string $column, CarbonImmutable $since, int $limit = 10): array
    {
        return $this->visits($since)
            ->whereNotNull($column)
            ->select($column, DB::raw('COUNT(*) as count'))
            ->groupBy($column)
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->{$column}, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * Visits per (utm_source, referrer) pair, grouped in SQL on the raw columns.
     * Turning these into readable sources (and merging hosts) happens in PHP.
     */
    public function sourcePairs(CarbonImmutable $since): array
    {
        return $this->visits($since)
            ->select('utm_source', 'referrer', DB::raw('COUNT(*) as count'))
            ->groupBy('utm_source', 'referrer')
            ->get()
            ->map(fn ($row) => ['utm_source' => $row->utm_source, 'referrer' => $row->referrer, 'count' => (int) $row->count])
            ->all();
    }
}
