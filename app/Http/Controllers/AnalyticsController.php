<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $days = max(1, min(365, (int) $request->query('days', 30)));
        $since = CarbonImmutable::today()->subDays($days - 1);

        $visits = fn (): Builder => Visit::where('created_at', '>=', $since);

        return Inertia::render('Analytics/Index', [
            'totalVisits' => $visits()->count(),
            'avgDuration' => (int) round($visits()->whereNotNull('duration')->avg('duration') ?? 0),
            'avgScrollDepth' => (int) round($visits()->whereNotNull('scroll_depth')->avg('scroll_depth') ?? 0),
            'visitsOverTime' => $this->visitsOverTime($visits(), $since),
            'topPages' => $this->topBy($visits(), 'url'),
            'topSources' => $this->topBy($visits(), 'utm_source'),
            'topReferrers' => $this->topBy($visits(), 'referrer'),
            'deviceBreakdown' => $this->topBy($visits(), 'device_type'),
            'topCountries' => $this->topBy($visits(), 'country'),
            'days' => $days,
        ]);
    }

    /**
     * Daily counts with zero-filled gaps, so the line chart shows quiet days as 0
     * instead of drawing a straight line across missing dates.
     */
    private function visitsOverTime(Builder $query, CarbonImmutable $since): array
    {
        $counts = $query
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

    /** Top 10 values of a column by visit count, ignoring NULLs. */
    private function topBy(Builder $query, string $column): array
    {
        return $query
            ->whereNotNull($column)
            ->select($column, DB::raw('COUNT(*) as count'))
            ->groupBy($column)
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['label' => $row->{$column}, 'count' => (int) $row->count])
            ->all();
    }
}
