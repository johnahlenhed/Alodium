<?php

namespace App\Support;

/**
 * WordPress shortcodes in imported content. WordPress replaces them with HTML
 * when it shows a post; without WordPress they would be literal "[gallery]" text.
 *
 * Only WordPress core's own shortcodes (KNOWN) are converted: every export can
 * contain them, and what they mean is fixed. Anything else in square brackets
 * is left exactly as it is. "[sic]", "a[i]" in a code sample or a footnote
 * "[1]" are text, and a plugin shortcode shown as text is more honest than
 * content that quietly disappears. Unknown tags that clearly are shortcodes
 * (attributes, a closing tag) are reported in the warnings.
 *
 * Every conversion that loses something (a gallery's layout, an audio player)
 * adds a warning, so the import result can say so for the post or page.
 *
 * The syntax follows WordPress's get_shortcode_regex(): [name attrs], [name /],
 * [name]content[/name], and [[name]] for a literal "[name]". One difference: a
 * quoted attribute value may contain "]" (caption="a [b] c"), which WordPress
 * itself cuts off.
 */
final class Shortcodes
{
    /** WordPress core's shortcodes (wp-includes/media.php and class-wp-embed.php). */
    public const KNOWN = ['audio', 'caption', 'embed', 'gallery', 'playlist', 'video', 'wp_caption'];

    /** wp_get_audio_extensions() and wp_get_video_extensions(). */
    private const AUDIO = ['mp3', 'ogg', 'flac', 'm4a', 'wav'];

    private const VIDEO = ['mp4', 'm4v', 'webm', 'ogv', 'flv'];

    private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const ALIGN = ['alignleft' => 'left', 'aligncenter' => 'center', 'alignright' => 'right'];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  array<string, array{url: string, alt: string}>  $attachments  the export's attachments, by id
     * @param  array<string, list<string>>  $children  attachment ids by the id of the post they were uploaded to, in gallery order
     */
    private function __construct(private array $attachments, private array $children, private string $itemId) {}

    /**
     * @param  array<string, array{url: string, alt: string}>  $attachments
     * @param  array<string, list<string>>  $children
     * @return array{html: string, warnings: list<string>}
     */
    public static function convert(string $html, array $attachments = [], array $children = [], string $itemId = ''): array
    {
        $converter = new self($attachments, $children, $itemId);
        $converted = preg_replace_callback(self::pattern(), $converter->replace(...), $html);

        if ($converted === null) {
            // A PCRE limit (a huge post): keep the content as it is rather than lose it.
            return ['html' => $html, 'warnings' => ['The shortcodes in this item could not be processed and are shown as text.']];
        }

        // Checked on the content without any core shortcode, escaped ones included:
        // a literal "[caption]text[/caption]" from [[caption]...] is not a problem.
        $converter->reportUnknown(preg_replace(self::pattern(), '', $html) ?? '');

        return ['html' => $converted, 'warnings' => $converter->warnings];
    }

    /**
     * Groups: 1 "[" of an escaped [[name]], 2 name, 3 attributes, 4 "/" of [name /],
     * 5 enclosed content, 6 "]" of an escaped [[name]]. Possessive quantifiers keep
     * long posts within PCRE's limits, as in WordPress.
     */
    private static function pattern(): string
    {
        $names = implode('|', self::KNOWN);

        return '/\[(\[?)('.$names.')(?![\w-])((?:"[^"]*"|\'[^\']*\'|[^\]"\'\/]|\/(?!\]))*+)'
            .'(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/';
    }

    private function replace(array $m): string
    {
        // [[gallery]] is WordPress's way of writing a literal "[gallery]".
        if ($m[1] === '[' && ($m[6] ?? '') === ']') {
            return substr($m[0], 1, -1);
        }

        $attributes = self::attributes($m[3]);
        $content = $m[5] ?? '';

        $html = match ($m[2]) {
            'caption', 'wp_caption' => $this->caption($attributes, $content),
            'embed' => $this->embed($content),
            'gallery' => $this->gallery($attributes),
            'audio' => $this->media('audio', self::AUDIO, $attributes, $content),
            'video' => $this->media('video', self::VIDEO, $attributes, $content),
            'playlist' => $this->playlist($attributes),
        };

        return $m[1].$html.($m[6] ?? '');
    }

    /** [caption align="aligncenter" caption="Text"]<img>[/caption], or the text after the image. */
    private function caption(array $attributes, string $content): string
    {
        $image = trim($content);
        $caption = trim($attributes['caption'] ?? '');
        if ($caption === '' && preg_match('#^\s*((?:<a\s[^>]*>\s*)?<img\s[^>]*>(?:\s*</a>)?)(.*)$#is', $content, $match)) {
            [$image, $caption] = [$match[1], trim($match[2])];
        }

        // The sanitiser keeps text-align on paragraphs only (no figure, no float), so
        // a centred caption stays centred and left/right become aligned, not wrapped.
        $align = self::ALIGN[$attributes['align'] ?? ''] ?? null;
        $style = $align ? " style=\"text-align: {$align}\"" : '';

        return "<p{$style}>{$image}</p>".($caption !== '' ? "\n<p{$style}><em>{$caption}</em></p>" : '');
    }

    /** [embed]https://www.youtube.com/watch?v=...[/embed]: the player needs WordPress, the link doesn't. */
    private function embed(string $content): string
    {
        $url = self::url($content);
        if ($url === null) {
            $this->warnings[] = 'An embed without a web address was removed.';

            return '';
        }

        $this->warnings[] = 'Embedded media (for example a video) is shown as a link.';

        return '<p>'.self::link($url, $url).'</p>';
    }

    /** [gallery ids="1,2,3"], or the images uploaded to the post (or to the post in id=""). */
    private function gallery(array $attributes): string
    {
        $ids = self::ids($attributes['ids'] ?? $attributes['include'] ?? '');
        $requested = $ids !== [] ? count($ids) : null;
        if ($ids === []) {
            $exclude = self::ids($attributes['exclude'] ?? '');
            $ids = array_values(array_diff($this->children[$attributes['id'] ?? $this->itemId] ?? [], $exclude));
        }

        $images = [];
        foreach ($ids as $id) {
            $attachment = $this->attachments[$id] ?? null;
            if ($attachment && self::hasExtension($attachment['url'], self::IMAGE)) {
                $images[] = '<img src="'.e($attachment['url']).'" alt="'.e($attachment['alt']).'" />';
            }
        }

        if ($images === []) {
            $this->warnings[] = 'A gallery was removed: none of its images are in the export file.';

            return '';
        }

        $count = count($images);
        $absent = $requested !== null ? $requested - $count : 0;
        $missing = $absent > 0 ? " {$absent} of its images ".($absent === 1 ? 'is' : 'are').' not in the export file.' : '';
        $this->warnings[] = "A gallery was converted to {$count} ".($count === 1 ? 'image' : 'images').' in one paragraph; the gallery layout is not kept.'.$missing;

        return '<p>'.implode(' ', $images).'</p>';
    }

    /**
     * [audio src="..."], [audio mp3="..."], [audio https://...] and [video ...]: a
     * link to the file. Without a file the player shows the first one uploaded to
     * the post, as in WordPress.
     */
    private function media(string $kind, array $extensions, array $attributes, string $content): string
    {
        $url = null;
        foreach (['src', ...$extensions, 0] as $key) {
            if ($url = self::url($attributes[$key] ?? null)) {
                break;
            }
        }
        $url ??= self::url($content) ?? $this->firstChild($extensions);
        $player = $kind === 'audio' ? 'An audio player' : 'A video player';

        if ($url === null) {
            $this->warnings[] = "{$player} was removed: no {$kind} file was found for it.";

            return '';
        }

        $this->warnings[] = "{$player} was replaced by a link to the file, which stays on the old site.";

        return '<p>'.self::link($url, self::fileName($url)).'</p>';
    }

    /** [playlist ids="..."], or the audio (type="video": video) files uploaded to the post. */
    private function playlist(array $attributes): string
    {
        $extensions = ($attributes['type'] ?? 'audio') === 'video' ? self::VIDEO : self::AUDIO;
        $ids = self::ids($attributes['ids'] ?? $attributes['include'] ?? '') ?: ($this->children[$attributes['id'] ?? $this->itemId] ?? []);

        $links = [];
        foreach ($ids as $id) {
            $url = $this->attachments[$id]['url'] ?? null;
            if ($url !== null && self::hasExtension($url, $extensions)) {
                $links[] = '<li>'.self::link($url, self::fileName($url)).'</li>';
            }
        }

        if ($links === []) {
            $this->warnings[] = 'A playlist was removed: none of its files are in the export file.';

            return '';
        }

        $this->warnings[] = 'A playlist was replaced by '.count($links).' '.(count($links) === 1 ? 'link' : 'links').' to the files, which stay on the old site.';

        return '<ul>'.implode('', $links).'</ul>';
    }

    /** Tags left in the content that clearly are shortcodes: with attributes, self-closing, or closed. */
    private function reportUnknown(string $html): void
    {
        preg_match_all('/\[([a-zA-Z][\w-]*)\s+[\w-]+\s*=|\[([a-zA-Z][\w-]*)\s*\/\]|\[\/([a-zA-Z][\w-]*)\]/', $html, $matches, PREG_SET_ORDER);

        $names = [];
        foreach ($matches as $match) {
            // Only one of the three groups matched: the opening tag, [name /] or [/name].
            $names[($match[3] ?? '') ?: (($match[2] ?? '') ?: $match[1])] = true;
        }
        if ($names === []) {
            return;
        }

        // Mostly plugin shortcodes; also a core one that couldn't be read (an unclosed quote).
        $list = array_map(fn ($name) => "[{$name}]", array_keys($names));
        $shown = implode(', ', array_slice($list, 0, 5)).(count($list) > 5 ? ' and '.(count($list) - 5).' more' : '');
        $this->warnings[] = "Shortcodes that could not be converted are shown as text: {$shown}.";
    }

    /**
     * WordPress's shortcode_parse_atts(): name="value", name='value', name=value, and
     * positional values. Values stay as written: a caption is HTML, as in WordPress;
     * addresses are decoded where they are used (url()).
     */
    private static function attributes(string $text): array
    {
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
        preg_match_all($pattern, preg_replace('/\x{00a0}|\x{200b}/u', ' ', $text) ?? $text, $matches, PREG_SET_ORDER);

        $attributes = [];
        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                $attributes[strtolower($m[1])] = $m[2];
            } elseif (($m[3] ?? '') !== '') {
                $attributes[strtolower($m[3])] = $m[4];
            } elseif (($m[5] ?? '') !== '') {
                $attributes[strtolower($m[5])] = $m[6];
            } else {
                $attributes[] = ($m[7] ?? '') !== '' ? $m[7] : ((($m[8] ?? '') !== '') ? $m[8] : ($m[9] ?? ''));
            }
        }

        return $attributes;
    }

    /** @return list<string> */
    private static function ids(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list)), fn ($id) => ctype_digit($id)));
    }

    private function firstChild(array $extensions): ?string
    {
        foreach ($this->children[$this->itemId] ?? [] as $id) {
            $url = $this->attachments[$id]['url'] ?? null;
            if ($url !== null && self::hasExtension($url, $extensions)) {
                return $url;
            }
        }

        return null;
    }

    /** An http(s) address from an attribute or enclosed text ("&amp;" decoded), or null. */
    private static function url(?string $value): ?string
    {
        $url = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }

    private static function hasExtension(string $url, array $extensions): bool
    {
        return in_array(strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)), $extensions, true);
    }

    private static function fileName(string $url): string
    {
        return rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) ?: $url;
    }

    private static function link(string $url, string $text): string
    {
        return '<a href="'.e($url).'">'.e($text).'</a>';
    }
}
