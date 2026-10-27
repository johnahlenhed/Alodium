<?php

namespace Tests\Feature;

use App\Models\ImportRun;
use App\Models\Post;
use App\Models\User;
use App\Services\WordPressImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Fakes\FakesImageDownloads;
use Tests\TestCase;

/**
 * docs/testdata/ssrf-smoke.xml, the file imported by hand on the production
 * server after a deploy: through the importer and the image jobs (sync queue),
 * with the fake resolver, which resolves IP literals and numeric hosts the way
 * the real one does. Nothing may be requested.
 */
class SsrfSmokeTest extends TestCase
{
    use FakesImageDownloads, RefreshDatabase;

    public function test_the_smoke_file_downloads_nothing_and_skips_every_image_as_not_allowed(): void
    {
        Storage::fake('public');
        config(['import.min_free_disk_bytes' => 0]);
        $this->fakeImageDownloads();

        $path = base_path('docs/testdata/ssrf-smoke.xml');
        $this->assertNotFalse(simplexml_load_file($path, options: LIBXML_NONET), 'The smoke file must be valid XML.');

        $results = app(WordPressImporter::class)->import($path, User::factory()->create(), true);

        $this->assertSame([], $results['errors']);
        $this->assertSame(1, $results['imported_posts']);
        $run = ImportRun::findOrFail($results['run_id']);
        $this->assertEqualsCanonicalizing(['169.254.169.254', '127.0.0.1', '::1', '2130706433'], $run->allowed_hosts);

        $this->assertSame([], $this->http->requests, 'No request may leave the server.');
        $this->assertSame(
            ['status' => 'done', 'images_found' => 5, 'images_downloaded' => 0, 'images_reused' => 0, 'images_skipped' => 5, 'bytes_downloaded' => 0],
            $run->only(['status', 'images_found', 'images_downloaded', 'images_reused', 'images_skipped', 'bytes_downloaded']),
        );
        $this->assertSame(['blocked address' => 5], $run->skipped_reasons);
        $this->assertEqualsCanonicalizing([
            'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1/x.png',
            'http://[::1]/x.png',
            'http://2130706433/x.png',
        ], array_column($run->failures, 'url'));

        // The draft keeps its original addresses and has no featured image.
        $post = Post::firstOrFail();
        $this->assertSame('draft', $post->status);
        $this->assertSame(4, substr_count($post->content, '<img src="http://'));
        $this->assertSame(0, Media::count());
    }
}
