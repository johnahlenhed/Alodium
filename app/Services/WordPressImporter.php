<?php

namespace App\Services;

use App\Jobs\DownloadContentImages;
use App\Models\ImportRun;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Support\ContentImages;
use App\Traits\GeneratesUniqueSlug;
use Carbon\Carbon;
use DOMDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Imports posts and pages from a WordPress WXR export (Tools > Export > All content).
 *
 * Design goals: reliable over fast. Every item is imported in isolation, so one
 * broken post is reported in `errors` instead of aborting the whole import.
 *
 * Every import is recorded as an ImportRun, with its result (the run page shows
 * it). With $downloadImages, the images in the imported content and the
 * featured images are downloaded afterwards on the queue (one
 * DownloadContentImages job per post or page), only from the exported site's
 * own hosts; the upload request itself downloads nothing.
 */
class WordPressImporter
{
    use GeneratesUniqueSlug;

    /** WordPress status => Alodium status. Anything else (private, pending, trash, future) is skipped. */
    private const STATUSES = ['publish' => 'published', 'draft' => 'draft'];

    private const TYPES = ['post' => Post::class, 'page' => Page::class];

    private array $results;

    /** Hosts images may be downloaded from (see allowedHosts()). */
    private array $allowedHosts = [];

    /** Posts and pages created by this import: [type, model, featured image URL or null]. */
    private array $created = [];

    /** HTML allowlist shared with page blocks (App\Services\ContentSanitizer). */
    public function __construct(private ContentSanitizer $sanitizer) {}

    /**
     * `found` counts every item in the file by WordPress post type (post, page,
     * attachment, nav_menu_item, ...), so "0 pages imported" can be told apart
     * from "the file contains no pages".
     *
     * @return array{imported_posts: int, imported_pages: int, skipped: list<array{title: string, type: string, reason: string}>, errors: list<string>, found: array<string, int>, run_id: int}
     */
    public function import(string $path, User $author, bool $downloadImages = false): array
    {
        $this->created = [];
        $run = ImportRun::create([
            'user_id' => $author->id,
            'status' => ImportRun::STATUS_IMPORTING,
            'download_images' => $downloadImages,
            'started_at' => now(),
        ]);
        $this->results = ['imported_posts' => 0, 'imported_pages' => 0, 'skipped' => [], 'errors' => [], 'found' => [], 'run_id' => $run->id];

        $this->ensureMemoryLimit('256M');
        // A large export takes a while to parse and save.
        @set_time_limit(0);

        try {
            $xml = $this->load($path);
        } catch (Throwable $e) {
            $this->results['errors'][] = $e->getMessage();
            $this->saveReport($run, ['status' => ImportRun::STATUS_FAILED, 'finished_at' => now()]);

            return $this->results;
        }

        $namespaces = $xml->getDocNamespaces(true);
        $wp = $namespaces['wp'] ?? null;
        $contentNs = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';

        if (! $wp || ! isset($xml->channel)) {
            $this->results['errors'][] = 'This does not look like a WordPress export file (no WordPress data found).';
            $this->saveReport($run, ['status' => ImportRun::STATUS_FAILED, 'finished_at' => now()]);

            return $this->results;
        }

        $items = $xml->channel->item;

        // First pass: count item types, and map attachment ids to URLs, since featured
        // images are separate "attachment" items referenced by id from each post.
        $attachments = [];
        foreach ($items as $item) {
            $meta = $item->children($wp);
            $type = $this->field($meta->post_type) ?: '(unknown)';
            $this->results['found'][$type] = ($this->results['found'][$type] ?? 0) + 1;

            if ($type === 'attachment' && trim((string) $meta->attachment_url) !== '') {
                $attachments[trim((string) $meta->post_id)] = trim((string) $meta->attachment_url);
            }
        }

        $this->allowedHosts = $this->allowedHosts($xml->channel->children($wp), $attachments);
        $run->update(['allowed_hosts' => $this->allowedHosts]);

        foreach ($items as $item) {
            $title = trim((string) $item->title);

            try {
                $this->importItem($item, $wp, $contentNs, $attachments, $author);
            } catch (Throwable $e) {
                $this->results['errors'][] = sprintf('"%s": %s', $title !== '' ? $title : '(untitled)', $e->getMessage());
            }
        }

        Log::info('WordPress import finished', [
            'found' => $this->results['found'],
            'imported_posts' => $this->results['imported_posts'],
            'imported_pages' => $this->results['imported_pages'],
            'skipped' => count($this->results['skipped']),
            'errors' => count($this->results['errors']),
            'wp_namespace' => $wp,
        ]);

        // Before the jobs are queued: a synchronous queue finishes the run inside queueImageDownloads().
        $this->saveReport($run);
        $this->queueImageDownloads($run, $downloadImages);

        return $this->results;
    }

    /** The text import's result, kept on the run for the run page. */
    private function saveReport(ImportRun $run, array $changes = []): void
    {
        $run->update([
            'imported_posts' => $this->results['imported_posts'],
            'imported_pages' => $this->results['imported_pages'],
            'report' => [
                'skipped' => $this->results['skipped'],
                'errors' => $this->results['errors'],
                'found' => $this->results['found'],
            ],
            ...$changes,
        ]);
    }

    /**
     * The hosts images may be downloaded from: the exported site's own
     * (wp:base_site_url, wp:base_blog_url) and every attachment's host, which for
     * a wordpress.com site includes its *.files.wordpress.com media host. Images
     * on any other host are third-party and stay where they are.
     *
     * @return list<string>
     */
    private function allowedHosts(SimpleXMLElement $channel, array $attachments): array
    {
        $urls = [(string) $channel->base_site_url, (string) $channel->base_blog_url, ...array_values($attachments)];
        $hosts = [];
        foreach ($urls as $url) {
            $host = strtolower(trim((string) parse_url(trim($url), PHP_URL_HOST), '[]'));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        // An all-digit host (2130706433) would come back from array_keys() as an int.
        return array_map('strval', array_keys($hosts));
    }

    /**
     * One DownloadContentImages job per created post or page with images from an
     * allowed host, in its content or as its featured image (an item with only a
     * featured image gets a job too). The run's totals are set before the first
     * job is queued, so a fast (or synchronous) job can never finish the run early.
     */
    private function queueImageDownloads(ImportRun $run, bool $downloadImages): void
    {
        $items = [];
        $found = 0;
        foreach ($downloadImages ? $this->created : [] as [$type, $record, $featuredUrl]) {
            $featuredUrl = $featuredUrl !== null && ContentImages::onAllowedHost($featuredUrl, $this->allowedHosts) ? $featuredUrl : null;
            $count = count(ContentImages::candidates($record->content, $this->allowedHosts)) + ($featuredUrl !== null ? 1 : 0);
            if ($count > 0) {
                $items[] = [$type, $record->id, $featuredUrl];
                $found += $count;
            }
        }

        if ($items === []) {
            $run->update(['status' => ImportRun::STATUS_DONE, 'finished_at' => now()]);

            return;
        }

        $run->update(['status' => ImportRun::STATUS_DOWNLOADING, 'items_total' => count($items), 'images_found' => $found]);
        foreach ($items as [$type, $id, $featuredUrl]) {
            DownloadContentImages::dispatch($run->id, $type, $id, featuredUrl: $featuredUrl);
        }
    }

    private function importItem(SimpleXMLElement $item, string $wp, string $contentNs, array $attachments, User $author): void
    {
        $meta = $item->children($wp);
        $type = $this->field($meta->post_type);

        // Attachments, menu items, revisions, custom post types, etc. are not content we import.
        if (! isset(self::TYPES[$type])) {
            return;
        }

        $title = trim(html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5));
        $wpStatus = $this->field($meta->status);

        if ($title === '') {
            $this->skip('(untitled)', $type, 'The item has no title.');

            return;
        }

        if (! isset(self::STATUSES[$wpStatus])) {
            $this->skip($title, $type, "Status \"{$wpStatus}\" is not imported (only published and draft).");

            return;
        }

        /** @var class-string<Model> $model */
        $model = self::TYPES[$type];
        $baseSlug = Str::slug($title) ?: 'untitled';

        // Re-running an import must not create copies: an existing slug means "already imported".
        if ($model::where('slug', $baseSlug)->exists()) {
            $this->skip($title, $type, "A {$type} with the slug \"{$baseSlug}\" already exists.");

            return;
        }

        $status = self::STATUSES[$wpStatus];

        $record = DB::transaction(fn () => $model::create([
            'author_id' => $author->id,
            'title' => $title,
            'slug' => $this->uniqueSlug($model, $title),
            'content' => $this->cleanContent((string) $item->children($contentNs)->encoded),
            'status' => $status,
            'published_at' => $status === 'published' ? $this->publishedAt($meta) : null,
        ]));

        $this->results[$type === 'post' ? 'imported_posts' : 'imported_pages']++;

        // The featured image is downloaded later, by the item's image job.
        $thumbnailId = $this->postMeta($meta, '_thumbnail_id');
        $this->created[] = [$type, $record, $thumbnailId !== null ? ($attachments[trim($thumbnailId)] ?? null) : null];
    }

    private function cleanContent(string $html): ?string
    {
        // Gutenberg block markers (<!-- wp:paragraph -->) and other comments.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // Shortcodes: [gallery ids="1,2"], [caption]...[/caption], [contact-form-7 /].
        // Only bracket pairs shaped like a shortcode tag are removed (the text between an
        // opening and closing tag is kept). A broader /\[.*?\]/s would also delete
        // things like "[1]" footnotes, and with an unmatched "[" could remove whole
        // paragraphs up to the next "]".
        $html = preg_replace('/\[\/?[a-zA-Z][\w-]*(?:\s[^\[\]]*)?\/?\]/', '', $html) ?? $html;

        // Protocol-relative images ("//host/x.jpg") become https, so they survive the
        // sanitiser (which removes "//" sources) and the image download can find them.
        $html = preg_replace('/(<img\b[^>]*?\ssrc\s*=\s*["\']?)\/\//i', '$1https://', $html) ?? $html;

        $html = $this->sanitizer->sanitize($this->autop($html));
        $html = trim(preg_replace('#<p>(\s|<br\s*/?>)*</p>#', '', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    /**
     * Classic-editor content is stored without <p> tags; WordPress adds them at
     * render time (wpautop). Do the same here so paragraphs survive the import:
     * text blocks separated by blank lines become <p>, single newlines become <br>.
     */
    private function autop(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", trim($html));
        $blocks = preg_split('/\n\s*\n/', $html) ?: [];
        // A chunk that already contains block-level markup is left alone: wrapping it in
        // <p> would produce invalid nesting (e.g. <p><div>) and stray empty paragraphs.
        $blockTag = '/<\/?(p|div|h[1-6]|ul|ol|li|blockquote|figure|table|pre|hr|section|article|header|footer|aside|dl)\b/i';

        $out = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $out[] = preg_match($blockTag, $block) ? $block : '<p>'.nl2br($block, false).'</p>';
        }

        return implode("\n", $out);
    }

    private function publishedAt(SimpleXMLElement $meta): Carbon
    {
        try {
            $gmt = (string) $meta->post_date_gmt;
            if ($gmt !== '' && ! str_starts_with($gmt, '0000')) {
                return Carbon::parse($gmt, 'UTC');
            }

            $local = (string) $meta->post_date;
            if ($local !== '' && ! str_starts_with($local, '0000')) {
                return Carbon::parse($local);
            }
        } catch (Throwable) {
            // Fall through to "now" for unparseable dates.
        }

        return now();
    }

    private function postMeta(SimpleXMLElement $meta, string $key): ?string
    {
        foreach ($meta->postmeta as $postmeta) {
            if ((string) $postmeta->meta_key === $key) {
                return (string) $postmeta->meta_value;
            }
        }

        return null;
    }

    /**
     * Parses with DOMDocument in "recover" mode so a truncated or slightly malformed
     * export still yields every item that could be read. Parse problems are reported
     * as a warning in `errors` instead of failing the whole import.
     *
     * LIBXML_NONET blocks network access while parsing, and entities are not
     * substituted (no LIBXML_NOENT), which protects against XXE attacks.
     */
    private function load(string $path): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new DOMDocument;
            $dom->recover = true;
            $loaded = $dom->load($path, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
            $problems = libxml_get_errors();

            if (! $loaded || ! $dom->documentElement) {
                throw new RuntimeException('The file could not be read as XML.'.$this->describe($problems));
            }

            if ($problems !== []) {
                $this->results['errors'][] = 'The XML file is damaged or incomplete. Everything that could be read was imported — please review the result.'.$this->describe($problems);
            }

            return simplexml_import_dom($dom);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param  list<\LibXMLError>  $problems */
    private function describe(array $problems): string
    {
        if ($problems === []) {
            return '';
        }

        $first = $problems[0];

        return sprintf(' (First problem, line %d: %s)', $first->line, trim($first->message));
    }

    private function skip(string $title, string $type, string $reason): void
    {
        $this->results['skipped'][] = ['title' => $title, 'type' => $type, 'reason' => $reason];
        Log::debug('WordPress import skipped item', compact('title', 'type', 'reason'));
    }

    /**
     * Keyword fields like post_type and status, normalised. Hand-edited or
     * pretty-printed exports can have whitespace around the CDATA
     * (<wp:post_type> <![CDATA[page]]> </wp:post_type>), which would otherwise
     * make " page " fail to match "page".
     */
    private function field(SimpleXMLElement $value): string
    {
        return strtolower(trim((string) $value));
    }

    /** Raise the memory limit for large exports, but never lower it (e.g. from unlimited). */
    private function ensureMemoryLimit(string $minimum): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current !== '-1' && ini_parse_quantity($current) < ini_parse_quantity($minimum)) {
            ini_set('memory_limit', $minimum);
        }
    }
}
