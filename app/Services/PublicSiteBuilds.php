<?php

namespace App\Services;

use App\Models\Page;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the last successful Astro rebuild put on the public site, so the page
 * editor can tell whether a page is live yet.
 *
 * RebuildAstroJob takes a snapshot of every page on the public site (id and
 * updated_at) just before it runs the build, and records it here when the build
 * succeeds. The snapshot is taken before Astro reads the API, so it never claims
 * more than the build actually contains: a page saved in between shows as still
 * pending until the next build (which that save queued) records it.
 *
 * Kept in a small JSON file next to the rebuild log, like OffsiteBackup's
 * record of the last upload: it must survive cache:clear.
 */
class PublicSiteBuilds
{
    /**
     * Every page on the public site now: its version (updated_at) and its address
     * (slug), since renaming a page moves it.
     *
     * @return array<int, array{updated_at: ?string, slug: string}>
     */
    public function snapshot(): array
    {
        return Page::onPublicSite()->get(['id', 'slug', 'updated_at'])
            ->mapWithKeys(fn (Page $page) => [$page->id => ['updated_at' => $page->updated_at?->toIso8601String(), 'slug' => $page->slug]])
            ->all();
    }

    /** A successful build, started at $startedAt, of the pages in $snapshot. */
    public function recordSuccess(CarbonImmutable $startedAt, array $snapshot): void
    {
        $this->write([...$this->read(), 'succeeded' => ['started_at' => $startedAt->toIso8601String(), 'pages' => $snapshot]]);
    }

    /** A build that failed for good (retries used up); the site keeps the previous build. */
    public function recordFailure(): void
    {
        $this->write([...$this->read(), 'failed_at' => now()->toIso8601String()]);
    }

    /**
     * The page on the public site, for the page editor.
     *
     * state:
     * - draft / scheduled: not public, so not built.
     * - live: the last build has this version of the page.
     * - pending: published, but not built yet (onSite: an earlier version is there).
     * - failed: the last build since the change failed; the site keeps the previous version.
     * - not_configured: this server doesn't rebuild the site (ASTRO_REBUILD_SCRIPT empty).
     * - unknown: no build recorded yet (for example right after this was deployed).
     *
     * url is where the page will be (and is, when live); currentUrl is where the
     * version on the site now is, which differs after a rename until the next build.
     *
     * @return array{state: string, path: string, url: ?string, onSite: bool, currentUrl: ?string, publishAt: ?string}
     */
    public function pageStatus(Page $page): array
    {
        $path = '/'.$page->slug;
        $base = rtrim((string) config('app.public_site_url'), '/');
        // Without PUBLIC_SITE_URL there is no address to link to.
        $link = fn (string $slug) => $base !== '' ? $base.'/'.$slug : null;
        $built = $this->read()['succeeded'] ?? null;
        $builtVersion = $built['pages'][$page->id] ?? null;
        // A record in another shape (hand-edited, or older) counts as not recorded.
        if (! is_array($builtVersion) || ! isset($builtVersion['slug'])) {
            $builtVersion = null;
        }

        $status = [
            'path' => $path,
            'url' => $link($page->slug),
            // Some version of the page is on the public site (if anything was recorded).
            'onSite' => $builtVersion !== null,
            'currentUrl' => $builtVersion !== null ? $link($builtVersion['slug']) : null,
            'publishAt' => $page->publish_at?->toIso8601String(),
        ];

        $isPublic = Page::onPublicSite()->whereKey($page->id)->exists();

        return ['state' => match (true) {
            ! $isPublic => $page->publish_at ? 'scheduled' : 'draft',
            ! config('services.astro.rebuild_script') => 'not_configured',
            $built === null => 'unknown',
            ($builtVersion['updated_at'] ?? null) === $page->updated_at?->toIso8601String() => 'live',
            $this->failedSince($page) => 'failed',
            default => 'pending',
        }, ...$status];
    }

    /** A build gave up after the page's last change (and after the last success). */
    private function failedSince(Page $page): bool
    {
        $record = $this->read();
        try {
            $failedAt = isset($record['failed_at']) ? CarbonImmutable::parse($record['failed_at']) : null;
            $succeededAt = isset($record['succeeded']['started_at']) ? CarbonImmutable::parse($record['succeeded']['started_at']) : null;
        } catch (Throwable) {
            return false;
        }

        return $failedAt !== null && $failedAt->gte($page->updated_at) && ($succeededAt === null || $failedAt->gt($succeededAt));
    }

    private function read(): array
    {
        $file = $this->file();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }

    /** Written to a temporary file first, so a reader never sees half a file. */
    private function write(array $data): void
    {
        $file = $this->file();
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $temporary = $file.'.tmp';
        file_put_contents($temporary, json_encode($data));
        rename($temporary, $file);
    }

    private function file(): string
    {
        return (string) config('services.astro.build_record');
    }
}
