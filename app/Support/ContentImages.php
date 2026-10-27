<?php

namespace App\Support;

/**
 * Finds and rewrites the images in stored rich text. The HTML is always
 * ContentSanitizer output (double-quoted attributes, entities encoded), so
 * targeted replacements are used instead of parsing and re-serialising with
 * DOMDocument: everything other than the replaced values stays byte for byte
 * the same, and the result is still a fixed point of the sanitiser.
 */
class ContentImages
{
    private const IMG = '/<img\b[^>]*>/i';

    private const SRC = '/(\ssrc=")([^"]*)(")/i';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * The image URLs to download, in order and each once: <img src> with an
     * absolute http(s) or protocol-relative URL whose host is on the allowlist.
     *
     * @param  list<string>  $allowedHosts
     * @return list<string> URLs as written in the content (entities decoded)
     */
    public static function candidates(?string $html, array $allowedHosts): array
    {
        $urls = [];
        foreach (self::sources($html) as $url) {
            if (self::onAllowedHost($url, $allowedHosts)) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /** Every <img src> value in the HTML, entities decoded. @return list<string> */
    public static function sources(?string $html): array
    {
        preg_match_all(self::IMG, (string) $html, $tags);

        $sources = [];
        foreach ($tags[0] as $tag) {
            if (preg_match(self::SRC, $tag, $match)) {
                $sources[] = self::decode($match[2]);
            }
        }

        return $sources;
    }

    /**
     * Replaces the src of every image whose URL is in $map (original URL =>
     * local /storage/ path), then unwraps links around those images: an <a>
     * whose only content is an image with a /storage/ src, and whose href is an
     * image file on an allowed host (WordPress's "link to the full size").
     *
     * @param  array<string, string>  $map
     */
    public static function rewrite(string $html, array $map, array $allowedHosts): string
    {
        $html = preg_replace_callback(self::IMG, function (array $tag) use ($map) {
            return preg_replace_callback(self::SRC, function (array $src) use ($map) {
                $url = self::decode($src[2]);

                return isset($map[$url]) ? $src[1].self::encode($map[$url]).$src[3] : $src[0];
            }, $tag[0]) ?? $tag[0];
        }, $html) ?? $html;

        return preg_replace_callback('/<a\b([^>]*)>\s*(<img\b[^>]*>)\s*<\/a>/i', function (array $link) use ($allowedHosts) {
            $href = preg_match('/\shref="([^"]*)"/i', $link[1], $match) ? self::decode($match[1]) : null;
            $src = preg_match(self::SRC, $link[2], $match) ? self::decode($match[2]) : '';

            $unwrap = str_starts_with($src, '/storage/') && $href !== null
                && self::onAllowedHost($href, $allowedHosts) && self::isImageUrl($href);

            return $unwrap ? $link[2] : $link[0];
        }, $html) ?? $html;
    }

    /**
     * img src="/storage/..." made absolute with $origin, for the public API, so a
     * change of domain needs no data migration. Only relative /storage/ paths in
     * <img src> change, so running it twice changes nothing more.
     */
    public static function absolutize(?string $html, string $origin): ?string
    {
        if ($html === null || ! str_contains($html, '/storage/')) {
            return $html;
        }
        $origin = rtrim($origin, '/');

        return preg_replace_callback(self::IMG, fn (array $tag) => preg_replace_callback(
            self::SRC,
            fn (array $src) => str_starts_with($src[2], '/storage/') ? $src[1].$origin.$src[2].$src[3] : $src[0],
            $tag[0],
        ) ?? $tag[0], $html) ?? $html;
    }

    /** An absolute http(s) URL, or a protocol-relative one, on one of the hosts. */
    public static function onAllowedHost(string $url, array $allowedHosts): bool
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        return in_array($scheme, ['http', 'https'], true) && $host !== '' && in_array($host, $allowedHosts, true);
    }

    private static function isImageUrl(string $url): bool
    {
        return in_array(strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Local paths are [a-z0-9/._-] only; encoded anyway, the same way the sanitiser would. */
    private static function encode(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
