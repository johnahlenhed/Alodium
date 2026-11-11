<?php

namespace App\Http\Controllers;

use App\Services\VisitStatistics;
use App\Support\TrafficSource;
use App\Support\UrlToContent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, VisitStatistics $stats, UrlToContent $urls): Response
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
            // UTM-tagged views only (App\Support\UtmValue): utm_term and utm_content aren't collected.
            'campaigns' => $this->withEditLinks($stats->campaigns($since), $urls),
            'topMediums' => $stats->topBy('utm_medium', $since),
            // The link builder's starting address.
            'publicSiteUrl' => config('app.public_site_url'),
            'days' => $days,
        ]);
    }

    /**
     * Each campaign's page with `editUrl`: the post's or page's edit screen when the
     * path is one (as the dashboard's Top content links them), otherwise null. One
     * lookup for all the paths (UrlToContent).
     */
    private function withEditLinks(array $campaigns, UrlToContent $urls): array
    {
        $content = $urls->resolve(array_column($campaigns, 'page'));

        return array_map(fn (array $row) => [...$row, 'editUrl' => match ($content[$row['page']]['type']) {
            'post' => route('posts.edit', $content[$row['page']]['id']),
            'page' => route('pages.edit', $content[$row['page']]['id']),
            default => null,
        }], $campaigns);
    }
}
