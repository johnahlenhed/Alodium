<?php

namespace App\Services;

use App\Models\Visit;
use App\Support\UrlToContent;
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

    /** Top values of a column by visit count, ignoring NULLs; equal counts by value, so the order is stable. */
    public function topBy(string $column, CarbonImmutable $since, int $limit = 10): array
    {
        return $this->visits($since)
            ->whereNotNull($column)
            ->select($column, DB::raw('COUNT(*) as count'))
            ->groupBy($column)
            ->orderByDesc('count')
            ->orderBy($column)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->{$column}, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * Page views per campaign, with the source and medium it came with: one row
     * per (utm_campaign, utm_source, utm_medium), most views first. Only views
     * with a campaign; a missing source or medium is null. The values are stored
     * cleaned and lowercase (App\Support\UtmValue), so SQL can group them as they are.
     *
     * `page` is the path the row's views landed on most (equal counts: the first
     * path alphabetically), with "/offer" and "/offer/" counted as one page
     * (UrlToContent::normalize()); `otherPages` how many other pages it landed on.
     * Grouped in SQL with the path too, then merged here, as the traffic sources
     * are (sourcePairs()): one row per campaign, source, medium and path, never per visit.
     *
     * @return list<array{campaign: string, source: ?string, medium: ?string, count: int, page: string, otherPages: int}>
     */
    public function campaigns(CarbonImmutable $since, int $limit = 10): array
    {
        $rows = $this->visits($since)
            ->whereNotNull('utm_campaign')
            ->select('utm_campaign', 'utm_source', 'utm_medium', 'url', DB::raw('COUNT(*) as count'))
            ->groupBy('utm_campaign', 'utm_source', 'utm_medium', 'url')
            ->get();

        $campaigns = [];
        foreach ($rows as $row) {
            $key = json_encode([$row->utm_campaign, $row->utm_source, $row->utm_medium]);
            $campaigns[$key] ??= ['campaign' => $row->utm_campaign, 'source' => $row->utm_source, 'medium' => $row->utm_medium, 'count' => 0, 'pages' => []];
            $path = UrlToContent::normalize($row->url);
            $campaigns[$key]['count'] += (int) $row->count;
            $campaigns[$key]['pages'][$path] = ($campaigns[$key]['pages'][$path] ?? 0) + (int) $row->count;
        }

        // Most views first; equal counts by campaign, source and medium (a missing one first, as in SQL).
        $campaigns = array_values($campaigns);
        usort($campaigns, fn ($a, $b) => [$b['count'], $a['campaign'], (string) $a['source'], (string) $a['medium']]
            <=> [$a['count'], $b['campaign'], (string) $b['source'], (string) $b['medium']]);

        return array_map(function (array $campaign) {
            $pages = $campaign['pages'];
            uksort($pages, fn ($a, $b) => [$pages[$b], (string) $a] <=> [$pages[$a], (string) $b]);
            unset($campaign['pages']);

            return [...$campaign, 'page' => (string) array_key_first($pages), 'otherPages' => count($pages) - 1];
        }, array_slice($campaigns, 0, $limit));
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
