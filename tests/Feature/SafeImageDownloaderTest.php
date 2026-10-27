<?php

namespace Tests\Feature;

use App\Services\ImageDownload\HostResolver;
use App\Services\ImageDownload\ImageDownloadFailed;
use App\Services\ImageDownload\SystemHostResolver;
use App\Services\SafeImageDownloader;
use Tests\Fakes\FakesImageDownloads;
use Tests\TestCase;

/** SafeImageDownloader with fake DNS and HTTP: no network is used. */
class SafeImageDownloaderTest extends TestCase
{
    use FakesImageDownloads;

    private const HOSTS = ['old.example', 'media.old.example'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImageDownloads();
    }

    private function downloader(): SafeImageDownloader
    {
        return app(SafeImageDownloader::class);
    }

    /** The reason the download is refused. */
    private function failure(string $url, array $hosts = self::HOSTS, ?int $maxBytes = null): string
    {
        try {
            $image = $this->downloader()->download($url, $hosts, $maxBytes);
            $image->delete();
        } catch (ImageDownloadFailed $e) {
            return $e->reason();
        }
        $this->fail("$url was downloaded");
    }

    public function test_a_good_image_of_each_type_is_downloaded_from_the_checked_address(): void
    {
        foreach (['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'] as $type => $mime) {
            $this->http->set("https://old.example/files/Photo%20One.$type?w=604", $this->imageResponse($type, 300, 200));

            $image = $this->downloader()->download("https://old.example/files/Photo%20One.$type?w=604", self::HOSTS);

            $this->assertSame($mime, $image->mimeType);
            $this->assertSame([300, 200], [$image->width, $image->height]);
            $this->assertSame('photo-one.'.($type === 'jpeg' ? 'jpg' : $type), $image->fileName);
            $this->assertFileExists($image->path);
            $this->assertSame(filesize($image->path), $image->bytes);
            $image->delete();
        }

        // Connected to the address that was checked, with the host kept for TLS/SNI.
        $this->assertSame(['host' => 'old.example', 'port' => 443, 'ip' => '93.184.215.14'], array_slice(end($this->http->requests), 1));
    }

    public function test_the_extension_comes_from_the_bytes_not_the_url(): void
    {
        $this->http->set('https://old.example/picture.jpg', $this->imageResponse('png'));

        $image = $this->downloader()->download('https://old.example/picture.jpg', self::HOSTS);
        $this->assertSame('picture.png', $image->fileName);
        $image->delete();

        $this->assertSame('image.webp', SafeImageDownloader::fileName('https://old.example/', 'webp'));
        $this->assertSame(80 + strlen('.png'), strlen(SafeImageDownloader::fileName('https://old.example/'.str_repeat('a', 300).'.png', 'png')));
    }

    public function test_a_host_that_is_not_from_the_export_is_skipped_without_a_lookup(): void
    {
        $this->assertSame('host not from the exported site', $this->failure('https://elsewhere.example/a.png'));
        $this->assertSame([], $this->dns->lookups);
        $this->assertSame([], $this->http->requests);
    }

    public function test_a_host_resolving_to_a_private_address_is_blocked(): void
    {
        $this->fakeImageDownloads([], ['old.example' => ['10.0.0.5']]);
        $this->assertSame('blocked address', $this->failure('https://old.example/a.png'));

        // Any one non-public address is enough, even next to a public one.
        $this->fakeImageDownloads([], ['old.example' => ['93.184.215.14', '::ffff:127.0.0.1']]);
        $this->assertSame('blocked address', $this->failure('https://old.example/a.png'));
        $this->assertSame([], $this->http->requests);

        $this->fakeImageDownloads([], ['old.example' => []]);
        $this->assertSame('host not found', $this->failure('https://old.example/a.png'));
    }

    public function test_decimal_hex_and_octal_host_spellings_are_caught_by_the_resolved_address(): void
    {
        // The real resolver: numeric spellings are parsed by the system (no DNS query).
        $this->app->instance(HostResolver::class, new SystemHostResolver);

        foreach (['2130706433', '0x7f000001', '0177.0.0.1', '0x7f.1'] as $host) {
            $this->assertSame('blocked address', $this->failure("http://$host/a.png", [$host]), $host);
        }
        $this->assertSame([], $this->http->requests);
    }

    public function test_urls_with_user_info_other_ports_schemes_or_fragments_are_refused(): void
    {
        foreach ([
            'https://user:secret@old.example/a.png',
            'https://user@old.example/a.png',
            'https://old.example:22/a.png',
            'http://old.example:8080/a.png',
            'ftp://old.example/a.png',
            'file:///etc/passwd',
            'gopher://old.example/a.png',
            'https://old.example/a.png#frag',
            '//old.example/a.png',
            'not a url',
        ] as $url) {
            $this->assertSame('invalid URL', $this->failure($url), $url);
        }
        $this->assertSame([], $this->http->requests);

        // Ports 80 and 443 are fine, also written out.
        $this->http->set('http://old.example:80/a.png', $this->imageResponse());
        $this->downloader()->download('http://old.example:80/a.png', self::HOSTS)->delete();
    }

    public function test_redirects_are_checked_hop_by_hop(): void
    {
        $this->fakeImageDownloads([], ['internal.old.example' => ['169.254.169.254']]);

        $this->http->set('https://old.example/a.png', ['status' => 302, 'headers' => ['Location' => 'https://internal.old.example/meta']]);
        $this->assertSame('blocked address', $this->failure('https://old.example/a.png', [...self::HOSTS, 'internal.old.example']));

        $this->http->set('https://old.example/b.png', ['status' => 301, 'headers' => ['Location' => 'https://evil.example/b.png']]);
        $this->assertSame('host not from the exported site', $this->failure('https://old.example/b.png'));

        $this->http->set('https://old.example/c.png', ['status' => 302, 'headers' => ['Location' => 'http://127.0.0.1/c.png']]);
        $this->assertSame('host not from the exported site', $this->failure('https://old.example/c.png'));

        // A relative redirect on an allowed host is followed.
        $this->http->set('https://old.example/d.png', ['status' => 302, 'headers' => ['Location' => '/files/d.png']]);
        $this->http->set('https://old.example/files/d.png', ['status' => 307, 'headers' => ['Location' => 'https://media.old.example/d.png']]);
        $this->http->set('https://media.old.example/d.png', $this->imageResponse());
        $this->downloader()->download('https://old.example/d.png', self::HOSTS)->delete();
    }

    public function test_at_most_3_redirects_are_followed(): void
    {
        foreach (range(1, 4) as $i) {
            $this->http->set("https://old.example/$i.png", ['status' => 302, 'headers' => ['Location' => '/'.($i + 1).'.png']]);
        }
        $this->http->set('https://old.example/5.png', $this->imageResponse());

        $this->assertSame('too many redirects', $this->failure('https://old.example/1.png'));
        $this->downloader()->download('https://old.example/2.png', self::HOSTS)->delete(); // 2 -> 3 -> 4 -> 5: three hops
    }

    public function test_an_oversized_body_is_cut_off_with_or_without_a_content_length(): void
    {
        config(['import.max_image_bytes' => 200_000]);
        $big = $this->imageBytes('png').str_repeat("\0", 1_000_000);

        // Declared too large: refused before any of the body is read.
        $this->http->set('https://old.example/declared.png', ['status' => 200, 'body' => $big]);
        $this->assertSame('too large', $this->failure('https://old.example/declared.png'));
        $this->assertSame(0, $this->http->bytesSent);

        // No Content-Length: stopped while streaming, just past the cap.
        $this->http->set('https://old.example/unknown.png', ['status' => 200, 'body' => $big, 'length' => null]);
        $this->assertSame('too large', $this->failure('https://old.example/unknown.png'));
        $this->assertLessThanOrEqual(200_000 + 65_536, $this->http->bytesSent);

        // A Content-Length that lies (small) is caught the same way.
        $sent = $this->http->bytesSent;
        $this->http->set('https://old.example/lying.png', ['status' => 200, 'body' => $big, 'length' => 1000]);
        $this->assertSame('too large', $this->failure('https://old.example/lying.png'));
        $this->assertLessThanOrEqual(200_000 + 65_536, $this->http->bytesSent - $sent);

        // A lower cap from the caller (the run's remaining budget) applies too.
        $this->http->set('https://old.example/small.png', $this->imageResponse('png', 400, 400));
        $this->assertSame('too large', $this->failure('https://old.example/small.png', self::HOSTS, 100));
    }

    public function test_only_jpeg_png_webp_and_gif_are_accepted_judged_by_the_bytes(): void
    {
        $cases = [
            'svg.png' => ['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>', 'image/png'],
            'error.jpg' => ['<!doctype html><html><body>Page not found</body></html>', 'image/jpeg'],
            'shell.jpg' => ['<?php echo shell_exec($_GET["c"]); ?>', 'image/jpeg'],
            'text.webp' => ['just text', 'image/webp'],
            'empty.gif' => ['', 'image/gif'],
            'truncated.png' => [substr($this->imageBytes('png'), 0, 20), 'image/png'],
        ];
        foreach ($cases as $name => [$body, $type]) {
            $this->http->set("https://old.example/$name", ['status' => 200, 'headers' => ['Content-Type' => $type], 'body' => $body]);
            $this->assertSame('not an image', $this->failure("https://old.example/$name"), $name);
        }
    }

    public function test_an_image_over_the_pixel_limit_is_refused(): void
    {
        config(['import.max_megapixels' => 1]);
        $this->http->set('https://old.example/huge.png', $this->imageResponse('png', 1001, 1000));
        $this->http->set('https://old.example/fine.png', $this->imageResponse('png', 1000, 1000));

        $this->assertSame('too many pixels', $this->failure('https://old.example/huge.png'));
        $this->downloader()->download('https://old.example/fine.png', self::HOSTS)->delete();
    }

    public function test_http_errors_and_timeouts_have_their_own_reasons(): void
    {
        $this->http->set('https://old.example/gone.png', ['status' => 404, 'body' => 'Not found']);
        $this->http->set('https://old.example/error.png', ['status' => 500, 'body' => 'Oops']);
        $this->http->set('https://old.example/slow.png', ['error' => 'timeout']);
        $this->http->set('https://old.example/refused.png', ['error' => 'connect']);

        $this->assertSame('HTTP 404', $this->failure('https://old.example/gone.png'));
        $this->assertSame('HTTP 500', $this->failure('https://old.example/error.png'));
        $this->assertSame('timeout', $this->failure('https://old.example/slow.png'));
        $this->assertSame('connection failed', $this->failure('https://old.example/refused.png'));
    }

    public function test_temporary_files_are_removed_when_a_download_fails(): void
    {
        $before = glob(sys_get_temp_dir().'/alodium-image-*') ?: [];
        $this->http->set('https://old.example/x.jpg', ['status' => 200, 'body' => '<html></html>']);
        $this->failure('https://old.example/x.jpg');

        $this->assertSame($before, glob(sys_get_temp_dir().'/alodium-image-*') ?: []);
    }

    public function test_urls_are_normalised_for_the_source_hash(): void
    {
        $this->assertSame('https://old.example/a.png?w=604', SafeImageDownloader::normaliseUrl('HTTP://Old.Example:80/a.png?w=604#x'));
        $this->assertSame('https://old.example/a.png', SafeImageDownloader::normaliseUrl('//old.example/a.png'));
        $this->assertNotSame(SafeImageDownloader::normaliseUrl('https://old.example/a.png?w=300'), SafeImageDownloader::normaliseUrl('https://old.example/a.png?w=604'));
    }
}
