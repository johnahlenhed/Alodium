<?php

namespace App\Services;

use App\Services\ImageDownload\DownloadedImage;
use App\Services\ImageDownload\FetchResult;
use App\Services\ImageDownload\HostResolver;
use App\Services\ImageDownload\ImageDownloadFailed;
use App\Services\ImageDownload\ImageHttpClient;
use finfo;
use Illuminate\Support\Str;

/**
 * Downloads one image from a URL found in an imported WordPress export. The
 * export may come from a third party, so the URL is untrusted and the server
 * must not become a proxy to internal addresses (SSRF):
 *
 * - http(s) on port 80 or 443 only, no user info or fragment;
 * - the host must be on the run's allowlist (the exported site's own hosts);
 * - every address the host resolves to must be public (isPublicAddress), and
 *   the connection goes to that checked address (pinned), not to a new lookup;
 * - redirects are followed by hand, at most 3, each hop checked the same way;
 * - the body is streamed and cut off at the byte cap, whatever Content-Length says;
 * - the file is judged by its bytes: JPEG, PNG, WebP or GIF, within the pixel limit.
 */
class SafeImageDownloader
{
    /** Detected type => file extension. */
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** getimagesize() types that match TYPES. */
    private const IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    public function __construct(private HostResolver $resolver, private ImageHttpClient $client) {}

    /**
     * @param  list<string>  $allowedHosts  lower-case host names images may come from
     * @param  int|null  $maxBytes  a lower cap than max_image_bytes (the run's remaining budget)
     *
     * @throws ImageDownloadFailed with the reason as its message
     */
    public function download(string $url, array $allowedHosts, ?int $maxBytes = null): DownloadedImage
    {
        $maxBytes = min((int) config('import.max_image_bytes'), $maxBytes ?? PHP_INT_MAX);
        // WordPress file names may contain spaces, which curl doesn't accept unencoded.
        $current = str_replace(' ', '%20', $url);

        for ($hop = 0; ; $hop++) {
            [$host, $port, $ip] = $this->check($current, $allowedHosts);

            $path = tempnam(sys_get_temp_dir(), 'alodium-image-');
            $file = fopen($path, 'wb');
            $state = ['status' => 0, 'location' => null, 'bytes' => 0, 'tooLarge' => false];

            try {
                $result = $this->client->fetch(
                    $current,
                    $host,
                    $port,
                    $ip,
                    function (int $status, array $headers) use (&$state, $maxBytes) {
                        $state['status'] = $status;
                        if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                            $state['location'] = $headers['location'];

                            return false; // the redirect is checked before it is followed
                        }
                        if ($status !== 200) {
                            return false;
                        }
                        // A declared size over the cap stops before any body is read. A missing
                        // or wrong Content-Length is caught while streaming below.
                        $declared = $headers['content-length'] ?? null;
                        if ($declared !== null && ctype_digit($declared) && (int) $declared > $maxBytes) {
                            $state['tooLarge'] = true;

                            return false;
                        }

                        return true;
                    },
                    function (string $chunk) use (&$state, $file, $maxBytes) {
                        $state['bytes'] += strlen($chunk);
                        if ($state['bytes'] > $maxBytes) {
                            $state['tooLarge'] = true;

                            return false;
                        }

                        return fwrite($file, $chunk) === strlen($chunk);
                    },
                );
            } finally {
                fclose($file);
            }

            if ($state['location'] !== null) {
                @unlink($path);
                if ($hop >= (int) config('import.max_redirects')) {
                    throw ImageDownloadFailed::because(ImageDownloadFailed::TOO_MANY_REDIRECTS);
                }
                $current = $this->resolveRedirect($current, $state['location']);

                continue;
            }

            try {
                $this->assertComplete($result, $state);

                return $this->inspect($path, $url, $state['bytes']);
            } catch (ImageDownloadFailed $e) {
                @unlink($path);
                throw $e;
            }
        }
    }

    /**
     * The URL rules, the allowlist and the address check for one hop.
     *
     * @return array{0: string, 1: int, 2: string} host, port and the checked IP to connect to
     */
    public function check(string $url, array $allowedHosts): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::INVALID_URL);
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::INVALID_URL);
        }

        if (! in_array($host, $allowedHosts, true)) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::NOT_ALLOWED_HOST);
        }

        // The resolved addresses are checked, not the text of the URL, so numeric
        // spellings like 2130706433 or 0x7f.0.0.1 are caught as 127.0.0.1.
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::NOT_RESOLVED);
        }
        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw ImageDownloadFailed::because(ImageDownloadFailed::BLOCKED_ADDRESS);
            }
        }

        return [$host, $port, $addresses[0]];
    }

    /**
     * Whether an IP address is on the public internet. Rejects loopback, private,
     * link-local (incl. the cloud metadata address 169.254.169.254), CGNAT,
     * unspecified, multicast, reserved, documentation and benchmarking ranges,
     * IPv6 unique-local and link-local, and IPv4-mapped forms of any of these.
     * IPv6 is allow-listed: only global unicast (2000::/3) minus its special blocks.
     */
    public static function isPublicAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach (self::BLOCKED_IPV4 as $range) {
                if (self::inRange($packed, $range)) {
                    return false;
                }
            }

            return true;
        }

        // ::ffff:a.b.c.d: an IPv4 address in IPv6 form, judged as that IPv4 address.
        if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return self::isPublicAddress((string) inet_ntop(substr($packed, 12)));
        }

        if (! self::inRange($packed, '2000::/3')) {
            return false; // ::1, ::, fc00::/7, fe80::/10, ff00::/8, 64:ff9b::/96, ...
        }
        foreach (self::BLOCKED_IPV6_GLOBAL as $range) {
            if (self::inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private const BLOCKED_IPV4 = [
        '0.0.0.0/8',        // "this network", including the unspecified 0.0.0.0
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // CGNAT (shared address space)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, including cloud metadata (169.254.169.254)
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // documentation (TEST-NET-1)
        '192.88.99.0/24',   // 6to4 relay (deprecated)
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation (TEST-NET-2)
        '203.0.113.0/24',   // documentation (TEST-NET-3)
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, including broadcast 255.255.255.255
    ];

    /** Special blocks inside 2000::/3. */
    private const BLOCKED_IPV6_GLOBAL = [
        '2001::/23',        // IETF protocol assignments (Teredo, benchmarking 2001:2::/48, ORCHID)
        '2001:db8::/32',    // documentation
        '2002::/16',        // 6to4: wraps an IPv4 address that isn't checked here
        '3fff::/20',        // documentation
    ];

    private static function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);
        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
    }

    /** A redirect target, made absolute against the URL that answered with it. */
    private function resolveRedirect(string $from, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $base = parse_url($from);
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($location, '//')) {
            return $base['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $directory = preg_replace('#/[^/]*$#', '/', $base['path'] ?? '/');

        return $origin.$directory.$location;
    }

    /** @param  array{status: int, bytes: int, tooLarge: bool}  $state */
    private function assertComplete(FetchResult $result, array $state): void
    {
        if ($state['tooLarge']) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::TOO_LARGE);
        }
        if ($result->error === 'timeout') {
            throw ImageDownloadFailed::because(ImageDownloadFailed::TIMEOUT);
        }
        if ($state['status'] !== 0 && $state['status'] !== 200) {
            throw ImageDownloadFailed::because('HTTP '.$state['status']);
        }
        if ($result->error !== null || $state['status'] !== 200) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::CONNECTION);
        }
    }

    /** The type from the bytes (finfo and the image header must agree), and the pixel limit. */
    private function inspect(string $path, string $url, int $bytes): DownloadedImage
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);

        if (! isset(self::TYPES[$mime]) || $size === false || ! in_array($size[2], self::IMAGE_TYPES, true) || image_type_to_mime_type($size[2]) !== $mime) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::NOT_AN_IMAGE);
        }

        [$width, $height] = $size;
        if ($width < 1 || $height < 1 || $width * $height > (int) config('import.max_megapixels') * 1_000_000) {
            throw ImageDownloadFailed::because(ImageDownloadFailed::TOO_MANY_PIXELS);
        }

        $extension = self::TYPES[$mime];

        return new DownloadedImage($path, $mime, $extension, $width, $height, $bytes, self::fileName($url, $extension));
    }

    /** "Some Photo (1).JPG?w=604" -> "some-photo-1.jpg": a slug of the URL's base name, at most 80 characters. */
    public static function fileName(string $url, string $extension): string
    {
        $base = pathinfo(rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))), PATHINFO_FILENAME);
        $slug = Str::limit(Str::slug($base), 80, '');

        return ($slug !== '' ? rtrim($slug, '-') : 'image').'.'.$extension;
    }

    /**
     * The URL as used for the source_hash: lower-case scheme and host, without the
     * default port and fragment, and http treated as https (WordPress content often
     * mixes both for the same file). The query is kept: ?w=604 is a different file.
     */
    public static function normaliseUrl(string $url): string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $port = isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true) ? ':'.$parts['port'] : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'https://'.$host.$port.($parts['path'] ?? '/').$query;
    }
}
