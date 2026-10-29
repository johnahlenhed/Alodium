<?php

namespace App\Http\Controllers;

use App\Services\VisitStatistics;
use App\Support\TrafficSource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, VisitStatistics $stats): Response
    {
        $days = max(1, min(365, (int) $request->query('days', 30)));
        $since = VisitStatistics::periodStart($days);
        // Sources and referrers come from the same rows, merged the same way as on the dashboard.
        $pairs = $stats->sourcePairs($since);
        $ownHost = TrafficSource::ownHost();

        return Inertia::render('Analytics/Index', [
            'totalVisits' => $stats->count($since),
            'avgDuration' => $stats->average('duration', $since),
            'avgScrollDepth' => $stats->average('scroll_depth', $since),
            'visitsOverTime' => $stats->daily($since),
            'topPages' => $stats->topBy('url', $since),
            'topSources' => TrafficSource::top($pairs, 10, $ownHost),
            'topReferrers' => TrafficSource::topReferrers($pairs, 10, $ownHost),
            'deviceBreakdown' => $stats->topBy('device_type', $since),
            'topCountries' => $stats->topBy('country', $since),
            'days' => $days,
        ]);
    }
}
