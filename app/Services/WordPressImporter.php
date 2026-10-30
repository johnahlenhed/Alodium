<?php

namespace App\Services;

use App\Jobs\DownloadContentImages;
use App\Models\ImportRun;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Support\ContentImages;
use App\Support\Shortcodes;
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

    /** A valid UTF-8 multibyte character, or (group 1) a byte that can't be part of one (repairEncoding()). */
    private const UTF8_OR_INVALID_BYTE = '/[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|([\x80-\xFF])/';

    private array $results;

    /** Hosts images may be downloaded from (see allowedHosts()). */
    private array $allowedHosts = [];

    /** Posts and pages created by this import: [type, model, featured image URL or null]. */
    private array $created = [];

    /** @var array<string, array{url: string, alt: string}> attachments by id, for [gallery] and [audio] */
    private array $attachmentMedia = [];

    /** @var array<string, list<string>> attachment ids by the post they were uploaded to, in gallery order */
    private array $attachmentChildren = [];

    /** @var array<int, array<string, int>> line => repair description => count (repairEncoding()) */
    private array $repairs = [];

    /** @var array<int, string> line => the first parse problem libxml reported on it */
    private array $problemLines = [];

    /** HTML allowlist shared with page blocks (App\Services\ContentSanitizer). */
    public function __construct(private ContentSanitizer $sanitizer) {}

    /**
     * `found` counts every item in the file by WordPress post type (post, page,
     * attachment, nav_menu_item, ...), so "0 pages imported" can be told apart
     * from "the file contains no pages".
     *
     * `warnings` are per imported post or page: what was converted, lost or repaired
     * (a gallery, an audio player, invalid characters, a damaged part of the file).
     *
     * @return array{imported_posts: int, imported_pages: int, skipped: list<array{title: string, type: string, reason: string}>, warnings: list<array{title: string, type: string, slug: string, message: string}>, errors: list<string>, found: array<string, int>, run_id: int}
     */
    public function import(string $path, User $author, bool $downloadImages = false): array
    {
        $this->created = $this->attachmentMedia = $this->attachmentChildren = $this->repairs = $this->problemLines = [];
        $run = ImportRun::create([
            'user_id' => $author->id,
            'status' => ImportRun::STATUS_IMPORTING,
            'download_images' => $downloadImages,
            'started_at' => now(),
        ]);
        $this->results = ['imported_posts' => 0, 'imported_pages' => 0, 'skipped' => [], 'warnings' => [], 'errors' => [], 'found' => [], 'run_id' => $run->id];

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
        $galleryOrder = [];
        $itemLines = [];
        foreach ($items as $item) {
            $meta = $item->children($wp);
            $type = $this->field($meta->post_type) ?: '(unknown)';
            $this->results['found'][$type] = ($this->results['found'][$type] ?? 0) + 1;
            $itemLines[] = dom_import_simplexml($item)->getLineNo();

            if ($type === 'attachment' && trim((string) $meta->attachment_url) !== '') {
                $id = trim((string) $meta->post_id);
                $attachments[$id] = trim((string) $meta->attachment_url);
                $this->attachmentMedia[$id] = ['url' => $attachments[$id], 'alt' => trim((string) $this->postMeta($meta, '_wp_attachment_image_alt'))];
                $parent = trim((string) $meta->post_parent);
                if ($parent !== '' && $parent !== '0') {
                    $galleryOrder[$parent][$id] = (int) $meta->menu_order;
                }
            }
        }

        // WordPress's gallery order for the images uploaded to a post: menu_order, then id.
        foreach ($galleryOrder as $parent => $ids) {
            uksort($ids, fn ($a, $b) => [$ids[$a], (int) $a] <=> [$ids[$b], (int) $b]);
            $this->attachmentChildren[(string) $parent] = array_map('strval', array_keys($ids));
        }

        $this->allowedHosts = $this->allowedHosts($xml->channel->children($wp), $attachments);
        $run->update(['allowed_hosts' => $this->allowedHosts]);

        // SimpleXML keys every item "item", so the position is counted here.
        $index = 0;
        foreach ($items as $item) {
            $title = trim((string) $item->title);
            // The item's lines in the file, for the repairs and parse problems inside it.
            $lines = [$itemLines[$index], ($itemLines[$index + 1] ?? PHP_INT_MAX) - 1];
            $index++;

            try {
                $this->importItem($item, $wp, $contentNs, $attachments, $author, $lines);
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
                'warnings' => $this->results['warnings'],
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

    /** @param  array{int, int}  $lines  first and last line of the item in the file */
    private function importItem(SimpleXMLElement $item, string $wp, string $contentNs, array $attachments, User $author, array $lines): void
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

        // WordPress sites often have a "Contact" or "Blog" page. Imported, it would
        // show as published here but never be built (Page::RESERVED_SLUGS). Skipped,
        // not renamed: a renamed copy would be imported again on every re-run,
        // since the check below looks for the original slug.
        if ($type === 'page' && ($owner = Page::reservedSlugOwner($baseSlug)) !== null) {
            $this->skip($title, $type, "The address /{$baseSlug} is {$owner}, so this page would never be shown. To keep its content, create a page with another title and copy the text across.");

            return;
        }

        // Re-running an import must not create copies: an existing slug means "already imported".
        if ($model::where('slug', $baseSlug)->exists()) {
            $this->skip($title, $type, "A {$type} with the slug \"{$baseSlug}\" already exists.");

            return;
        }

        $status = self::STATUSES[$wpStatus];
        $warnings = $this->repairWarnings($lines);

        // libxml stopped reading part of this item (recover mode carries on after the
        // problem), so its text may be cut short. It is not published unchecked.
        $problem = $this->problemIn($lines);
        if ($problem !== null) {
            $warnings[] = "The export file is damaged inside this item ({$problem}), so its content may be incomplete. It was imported as a draft, to be checked before it is published.";
            $status = 'draft';
        }

        [$content, $contentWarnings] = $this->cleanContent((string) $item->children($contentNs)->encoded, trim((string) $meta->post_id));

        $record = DB::transaction(fn () => $model::create([
            'author_id' => $author->id,
            'title' => $title,
            'slug' => $this->uniqueSlug($model, $title),
            'content' => $content,
            'status' => $status,
            'published_at' => $status === 'published' ? $this->publishedAt($meta) : null,
        ]));

        $this->results[$type === 'post' ? 'imported_posts' : 'imported_pages']++;
        $this->warn($type, $record, [...$warnings, ...$contentWarnings]);

        // The featured image is downloaded later, by the item's image job.
        $thumbnailId = $this->postMeta($meta, '_thumbnail_id');
        $this->created[] = [$type, $record, $thumbnailId !== null ? ($attachments[trim($thumbnailId)] ?? null) : null];
    }

    /**
     * @param  string  $itemId  the item's WordPress id: [gallery] without ids shows the images uploaded to it
     * @return array{0: ?string, 1: list<string>} the content to store, and what was lost or converted
     */
    private function cleanContent(string $html, string $itemId): array
    {
        // Gutenberg block markers (<!-- wp:paragraph -->) and other comments.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // WordPress's own shortcodes ([gallery], [caption], [audio], ...) become images,
        // paragraphs and links. Any other bracketed text ("[sic]", "a[i]", a plugin's
        // [contact-form-7]) is left as it is; see App\Support\Shortcodes.
        ['html' => $html, 'warnings' => $warnings] = Shortcodes::convert($html, $this->attachmentMedia, $this->attachmentChildren, $itemId);

        // Protocol-relative images ("//host/x.jpg") become https, so they survive the
        // sanitiser (which removes "//" sources) and the image download can find them.
        $html = preg_replace('/(<img\b[^>]*?\ssrc\s*=\s*["\']?)\/\//i', '$1https://', $html) ?? $html;

        $html = $this->sanitizer->sanitize($this->autop($html));
        $html = trim(preg_replace('#<p>(\s|<br\s*/?>)*</p>#', '', $html) ?? $html);

        return [$html === '' ? null : $html, $warnings];
    }

    /**
     * Records what this post or page lost or had changed, named by title and slug.
     * The same message several times (nine galleries) is listed once, with a count.
     *
     * @param  list<string>  $messages
     */
    private function warn(string $type, Model $record, array $messages): void
    {
        foreach (array_count_values($messages) as $message => $count) {
            $this->results['warnings'][] = [
                'title' => $record->title,
                'type' => $type,
                'slug' => $record->slug,
                'message' => $count > 1 ? "{$message} ({$count} times)" : $message,
            ];
        }
    }

    /** @param  array{int, int}  $lines */
    private function repairWarnings(array $lines): array
    {
        $controls = 0;
        $characters = [];
        foreach ($this->repairs as $line => $repair) {
            if ($line >= $lines[0] && $line <= $lines[1]) {
                $controls += $repair['control'] ?? 0;
                array_push($characters, ...($repair['utf8'] ?? []));
            }
        }

        $warnings = [];
        if ($characters !== []) {
            $count = count($characters);
            $examples = implode(' ', array_slice(array_unique($characters), 0, 5));
            $warnings[] = ($count === 1 ? '1 character that was not valid UTF-8 was' : "{$count} characters that were not valid UTF-8 were")
                ." read as Windows-1252 ({$examples}). Check that the text looks right.";
        }
        if ($controls > 0) {
            $warnings[] = ($controls === 1 ? '1 invisible control character that XML does not allow was' : "{$controls} invisible control characters that XML does not allow were")
                .' removed (for example from text pasted from Word).';
        }

        return $warnings;
    }

    /** "line 12: Opening and ending tag mismatch" for the first parse problem inside the item, or null. */
    private function problemIn(array $lines): ?string
    {
        foreach ($this->problemLines as $line => $message) {
            if ($line >= $lines[0] && $line <= $lines[1]) {
                return "line {$line}: {$message}";
            }
        }

        return null;
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
     * as a warning in `errors` instead of failing the whole import, and remembered by
     * line, so the posts and pages they are in get their own warning (problemIn()).
     *
     * LIBXML_NONET blocks network access while parsing, and entities are not
     * substituted (no LIBXML_NOENT), which protects against XXE attacks.
     */
    private function load(string $path): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = (string) @file_get_contents($path);
            if (trim($xml) === '') {
                throw new RuntimeException('The file could not be read as XML.');
            }

            $dom = new DOMDocument;
            $dom->recover = true;
            // LIBXML_BIGLINES: real line numbers past line 65535, to find the item a problem is in.
            $loaded = $dom->loadXML($this->repairEncoding($xml), LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_BIGLINES);
            $problems = libxml_get_errors();

            if (! $loaded || ! $dom->documentElement) {
                throw new RuntimeException('The file could not be read as XML.'.$this->describe($problems));
            }

            foreach ($problems as $problem) {
                $this->problemLines[$problem->line] ??= trim($problem->message);
            }
            if ($problems !== []) {
                $this->results['errors'][] = 'The XML file is damaged or incomplete. Everything that could be read was imported — please review the result. Posts and pages with a problem are listed under the warnings and were imported as drafts.'.$this->describe($problems);
            }

            return simplexml_import_dom($dom);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Characters libxml can't read make it drop the rest of the text they are in
     * (recover mode): one stray byte emptied a whole post, which was still
     * published. Two kinds are repaired before parsing, and each repair is
     * remembered by line, so the post or page gets a warning (repairWarnings()):
     *
     * - control characters XML does not allow (form feeds and the like, from text
     *   pasted from Word) are removed; they are invisible anyway;
     * - in a UTF-8 file, a byte that is not valid UTF-8 is read as Windows-1252,
     *   which is almost always what it is (0xE9 is "é" from an older system).
     *
     * Files in UTF-16 or UTF-32 are left alone: there, bytes below 0x20 are part of
     * ordinary characters. Other declared encodings (ISO-8859-1) libxml converts itself.
     */
    private function repairEncoding(string $xml): string
    {
        if (str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF")) {
            return $xml;
        }
        $declared = preg_match('/^(?:\xEF\xBB\xBF)?<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']+)/i', $xml, $match) ? strtolower($match[1]) : 'utf-8';
        if (str_starts_with($declared, 'utf-16') || str_starts_with($declared, 'utf-32')) {
            return $xml;
        }

        $xml = $this->repair($xml, '/([\x00-\x08\x0B\x0C\x0E-\x1F])/', function (string $byte, int $line) {
            $this->repairs[$line]['control'] = ($this->repairs[$line]['control'] ?? 0) + 1;

            return '';
        });

        if (in_array($declared, ['utf-8', 'utf8'], true) && ! mb_check_encoding($xml, 'UTF-8')) {
            $xml = $this->repair($xml, self::UTF8_OR_INVALID_BYTE, function (string $byte, int $line) {
                $character = mb_convert_encoding($byte, 'UTF-8', 'Windows-1252');
                $this->repairs[$line]['utf8'][] = $character;

                return $character;
            });
        }

        return $xml;
    }

    /**
     * Replaces group 1 of every match with $replace(byte, line); other matches stay.
     * The repairs never add or remove a line break, so the lines are the file's own.
     * If PCRE fails (a limit), the file is returned unrepaired, as before.
     */
    private function repair(string $xml, string $pattern, callable $replace): string
    {
        $line = 1;
        $position = 0;
        $repaired = preg_replace_callback($pattern, function (array $match) use ($xml, $replace, &$line, &$position) {
            if (! isset($match[1]) || $match[1][1] < 0) {
                return $match[0][0];
            }
            $line += substr_count($xml, "\n", $position, $match[1][1] - $position);
            $position = $match[1][1];

            return $replace($match[1][0], $line);
        }, $xml, flags: PREG_OFFSET_CAPTURE);

        return $repaired ?? $xml;
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
