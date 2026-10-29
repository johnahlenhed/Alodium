<?php

namespace App\Support;

/**
 * A readable traffic source for a page view: the utm_source when the link had
 * one, otherwise the referring site's host, otherwise "Direct".
 */
class TrafficSource
{
    public const DIRECT = 'Direct';

    public const INTERNAL = 'Your own site';

    /** $ownHost: the public site's host; referrers from it are navigation within the site. */
    public static function label(?string $utmSource, ?string $referrer, ?string $ownHost = null): string
    {
        if (is_string($utmSource) && trim($utmSource) !== '') {
            return trim($utmSource);
        }

        $host = self::host($referrer);
        if ($host === null) {
            return self::DIRECT;
        }

        return $ownHost !== null && $host === self::host($ownHost) ? self::INTERNAL : $host;
    }

    /** The public site's host (PUBLIC_SITE_URL), or null when it isn't set: then nothing counts as internal. */
    public static function ownHost(): ?string
    {
        $url = config('app.public_site_url');

        return $url ? self::host($url) : null;
    }

    /** "https://www.Google.com/search?q=x" -> "google.com". Accepts "google.com" without a scheme too. */
    public static function host(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'http://'.$url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Merges (utm_source, referrer, count) rows into the top sources.
     *
     * @param  iterable<array{utm_source: ?string, referrer: ?string, count: int}>  $rows
     * @return list<array{label: string, count: int}>
     */
    public static function top(iterable $rows, int $limit = 5, ?string $ownHost = null): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $label = self::label($row['utm_source'], $row['referrer'], $ownHost);
            $totals[$label] = ($totals[$label] ?? 0) + $row['count'];
        }

        $top = [];
        foreach ($totals as $label => $count) {
            $top[] = ['label' => (string) $label, 'count' => $count];
        }
        // Most views first; equal counts by name, so the order is stable.
        usort($top, fn ($a, $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return array_slice($top, 0, $limit);
    }

    /**
     * The top referring URLs from the same rows, as stored (full URL), leaving out
     * the site's own pages: a referrer on $ownHost is navigation within the site,
     * not a referral.
     *
     * @param  iterable<array{utm_source: ?string, referrer: ?string, count: int}>  $rows
     * @return list<array{label: string, count: int}>
     */
    public static function topReferrers(iterable $rows, int $limit = 10, ?string $ownHost = null): array
    {
        $own = $ownHost !== null ? self::host($ownHost) : null;
        $totals = [];
        foreach ($rows as $row) {
            $referrer = trim((string) $row['referrer']);
            if ($referrer === '' || ($own !== null && self::host($referrer) === $own)) {
                continue;
            }
            $totals[$referrer] = ($totals[$referrer] ?? 0) + $row['count'];
        }

        $top = [];
        foreach ($totals as $referrer => $count) {
            $top[] = ['label' => (string) $referrer, 'count' => $count];
        }
        usort($top, fn ($a, $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return array_slice($top, 0, $limit);
    }
}
