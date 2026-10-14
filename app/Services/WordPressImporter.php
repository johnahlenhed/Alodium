<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Traits\GeneratesUniqueSlug;
use Carbon\Carbon;
use DOMDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Throwable;

/**
 * Imports posts and pages from a WordPress WXR export (Tools > Export > All content).
 *
 * Design goals: reliable over fast. Every item is imported in isolation, so one
 * broken post is reported in `errors` instead of aborting the whole import.
 */
class WordPressImporter
{
    use GeneratesUniqueSlug;

    /** WordPress status => Alodium status. Anything else (private, pending, trash, future) is skipped. */
    private const STATUSES = ['publish' => 'published', 'draft' => 'draft'];

    private const TYPES = ['post' => Post::class, 'page' => Page::class];

    /** Elements kept as-is (with only the listed attributes). */
    private const ALLOWED = [
        'p' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'strong' => [], 'em' => [], 'blockquote' => [], 'br' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /**
     * Wrapper/inline elements that are removed but whose text is kept. Without this,
     * the sanitizer would drop e.g. a whole <div> or <table> including its content.
     * Elements in neither list (script, style, iframe, form, ...) are dropped entirely.
     */
    private const UNWRAP = [
        'div', 'span', 'section', 'article', 'header', 'footer', 'aside', 'main', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'pre', 'code', 'b', 'i', 'u',
        's', 'del', 'ins', 'small', 'sub', 'sup', 'mark', 'abbr', 'cite', 'q', 'dl', 'dt', 'dd',
        'center', 'font', 'label', 'time',
    ];

    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private array $results;

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            // Default is 20 000 bytes, which would silently truncate long posts.
            ->withMaxInputLength(-1);

        foreach (self::ALLOWED as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }
        foreach (self::UNWRAP as $element) {
            $config = $config->blockElement($element);
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * `found` counts every item in the file by WordPress post type (post, page,
     * attachment, nav_menu_item, ...), so "0 pages imported" can be told apart
     * from "the file contains no pages".
     *
     * @return array{imported_posts: int, imported_pages: int, skipped: list<array{title: string, type: string, reason: string}>, errors: list<string>, found: array<string, int>}
     */
    public function import(string $path, User $author): array
    {
        $this->results = ['imported_posts' => 0, 'imported_pages' => 0, 'skipped' => [], 'errors' => [], 'found' => []];

        $this->ensureMemoryLimit('256M');
        // Downloading featured images can take up to 10 s each.
        @set_time_limit(0);

        try {
            $xml = $this->load($path);
        } catch (Throwable $e) {
            $this->results['errors'][] = $e->getMessage();

            return $this->results;
        }

        $namespaces = $xml->getDocNamespaces(true);
        $wp = $namespaces['wp'] ?? null;
        $contentNs = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';

        if (! $wp || ! isset($xml->channel)) {
            $this->results['errors'][] = 'This does not look like a WordPress export file (no WordPress data found).';

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

        return $this->results;
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

        $thumbnailId = $this->postMeta($meta, '_thumbnail_id');
        if ($thumbnailId !== null && isset($attachments[$thumbnailId])) {
            $this->attachFeaturedImage($record, $attachments[$thumbnailId]);
        }
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
     * Best effort: any failure (404, timeout, not an image, too large) is silently
     * skipped. The post itself is already imported at this point.
     */
    private function attachFeaturedImage(Model $record, string $url): void
    {
        try {
            if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                return;
            }

            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                return;
            }

            $body = $response->body();
            if (strlen($body) > 10 * 1024 * 1024) {
                return;
            }

            // Check the actual bytes, not the remote server's Content-Type header.
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
            if (! in_array($mime, self::IMAGE_MIME_TYPES, true)) {
                return;
            }

            $fileName = basename(urldecode((string) parse_url($url, PHP_URL_PATH))) ?: 'featured-image';

            $record->addMediaFromString($body)
                ->usingFileName(Str::limit($fileName, 200, ''))
                ->usingName(pathinfo($fileName, PATHINFO_FILENAME))
                ->toMediaCollection('featured');
        } catch (Throwable) {
            // Silently skipped by design.
        }
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
