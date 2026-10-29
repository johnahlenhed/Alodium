<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Models\Submission;
use App\Models\User;
use App\Services\SiteStatus;
use App\Services\VisitStatistics;
use App\Support\TrafficSource;
use App\Support\UrlToContent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin start page. Editors get the content and submission sections only;
 * visitor, traffic and system data are admin-only and not even computed for
 * editors (see build(), the one place where the split is made).
 */
class DashboardController extends Controller
{
    /** Periods offered for the visitor summary, in days. */
    public const PERIODS = [7, 30];

    public function __construct(
        private VisitStatistics $stats,
        private UrlToContent $urls,
        private SiteStatus $siteStatus,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', $this->build($request->user(), (int) $request->query('days', 30)));
    }

    /** Props for $user's dashboard. The role split lives here and nowhere else. */
    public function build(User $user, int $days): array
    {
        $props = [
            'userName' => $user->name,
            'publicSiteUrl' => config('app.public_site_url'),
            'content' => $this->contentStatus(),
            'submissions' => $this->submissions(),
        ];

        if (! $user->isAdmin()) {
            return $props;
        }

        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $since = VisitStatistics::periodStart($days);

        return [
            ...$props,
            'visitors' => $this->visitorSummary($days, $since),
            'topContent' => $this->topContent($since),
            'sources' => TrafficSource::top($this->stats->sourcePairs($since), 5, TrafficSource::ownHost()),
            'siteStatus' => $this->siteStatus->summary(),
        ];
    }

    /** Page views in the period, compared with the period of the same length before it. */
    private function visitorSummary(int $days, CarbonImmutable $since): array
    {
        $current = $this->stats->count($since);
        $previous = $this->stats->count($since->subDays($days), $since);

        return [
            'days' => $days,
            'pageViews' => $current,
            'previousPageViews' => $previous,
            // Percentage change; null when there is nothing to compare with.
            'change' => $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null,
            'isNew' => $previous === 0 && $current > 0,
            'avgDuration' => $this->stats->average('duration', $since),
            'avgScrollDepth' => $this->stats->average('scroll_depth', $since),
            'daily' => $this->stats->daily($since),
            'timezone' => config('app.timezone'),
        ];
    }

    /** The 5 most viewed paths, with "/about" and "/about/" counted together, and their titles. */
    private function topContent(CarbonImmutable $since): array
    {
        $totals = [];
        foreach ($this->stats->topBy('url', $since, 100) as $row) {
            $path = UrlToContent::normalize($row['label']);
            $totals[$path] = ($totals[$path] ?? 0) + $row['count'];
        }
        // Most views first; equal counts by path, so the order is stable.
        uksort($totals, fn ($a, $b) => [$totals[$b], (string) $a] <=> [$totals[$a], (string) $b]);
        $totals = array_slice($totals, 0, 5, true);

        $resolved = $this->urls->resolve(array_keys($totals));

        $rows = [];
        foreach ($totals as $path => $count) {
            $content = $resolved[$path];
            $rows[] = [
                'path' => (string) $path,
                'title' => $content['title'],
                'type' => $content['type'],
                'count' => $count,
                'editUrl' => match ($content['type']) {
                    'post' => route('posts.edit', $content['id']),
                    'page' => route('pages.edit', $content['id']),
                    default => null,
                },
            ];
        }

        return $rows;
    }

    /** Counts per status for posts and pages (one aggregate query each) and what needs attention. */
    private function contentStatus(): array
    {
        $summary = fn (string $model) => $model::query()->selectRaw(
            "SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
             SUM(CASE WHEN status = 'draft' AND publish_at IS NULL THEN 1 ELSE 0 END) AS drafts,
             SUM(CASE WHEN status = 'draft' AND publish_at IS NOT NULL THEN 1 ELSE 0 END) AS scheduled,
             MIN(CASE WHEN status = 'draft' THEN publish_at END) AS next_scheduled,
             SUM(CASE WHEN status = 'published' AND (meta_description IS NULL OR meta_description = '') THEN 1 ELSE 0 END) AS missing_meta"
        )->first();

        $posts = $summary(Post::class);
        $pages = $summary(Page::class);

        $next = array_filter([$posts->next_scheduled, $pages->next_scheduled]);

        $missingMeta = fn (string $table, string $type) => DB::table($table)
            ->select('id', 'title', 'published_at', DB::raw("'{$type}' as type"))
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('meta_description')->orWhere('meta_description', ''));

        $needsMeta = $missingMeta('posts', 'post')
            ->unionAll($missingMeta('pages', 'page'))
            ->orderByDesc('published_at')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'title' => $row->title,
                'type' => $row->type,
                'editUrl' => route($row->type === 'post' ? 'posts.edit' : 'pages.edit', $row->id),
            ])
            ->all();

        return [
            'publishedPosts' => (int) $posts->published,
            'publishedPages' => (int) $pages->published,
            'drafts' => (int) $posts->drafts + (int) $pages->drafts,
            'scheduled' => (int) $posts->scheduled + (int) $pages->scheduled,
            'nextScheduledAt' => $next ? CarbonImmutable::parse(min($next), 'UTC')->toIso8601String() : null,
            'missingMetaCount' => (int) $posts->missing_meta + (int) $pages->missing_meta,
            'missingMeta' => $needsMeta,
        ];
    }

    /** The unread count and the 3 latest, as plain text (the page renders it escaped). */
    private function submissions(): array
    {
        return [
            'unread' => Submission::where('read', false)->count(),
            'latest' => Submission::latest()->latest('id')->limit(3)->get()->map(fn (Submission $submission) => [
                'id' => $submission->id,
                'name' => $submission->name,
                'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $submission->message), 80),
                'read' => $submission->read,
                'created_at' => $submission->created_at,
            ])->all(),
        ];
    }
}
