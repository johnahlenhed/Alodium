<?php

namespace Tests\Feature;

use App\Jobs\DownloadContentImages;
use App\Jobs\RebuildAstroJob;
use App\Models\ImportRun;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\ContentSanitizer;
use App\Services\ImportedImageStore;
use App\Services\WordPressImporter;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Fakes\FakesImageDownloads;
use Tests\TestCase;

/** Downloading the images inside imported WordPress content (DownloadContentImages and friends). */
class ImportImageDownloadTest extends TestCase
{
    use FakesImageDownloads, RefreshDatabase;

    private const MEDIA = 'https://files.old.example';

    private User $admin;

    private int $id = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['import.min_free_disk_bytes' => 0]);
        $this->admin = User::factory()->create();
        $this->fakeImageDownloads();
    }

    // --- helpers -------------------------------------------------------------------

    /** A WXR export whose own hosts are old.example and files.old.example (via an attachment). */
    private function wxr(string $items): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <title>Old site</title>
    <wp:base_site_url>https://old.example</wp:base_site_url>
    <wp:base_blog_url>https://blog.old.example</wp:base_blog_url>
    <item>
        <title>Attachment</title>
        <wp:post_id>1</wp:post_id>
        <wp:status>inherit</wp:status>
        <wp:post_type>attachment</wp:post_type>
        <wp:attachment_url><![CDATA[https://files.old.example/2020/01/attachment.jpg]]></wp:attachment_url>
    </item>
    {$items}
</channel>
</rss>
XML;
    }

    private function item(string $title, string $content, string $type = 'post', string $status = 'publish'): string
    {
        $id = $this->id++;

        return <<<XML
<item>
    <title><![CDATA[{$title}]]></title>
    <content:encoded><![CDATA[{$content}]]></content:encoded>
    <wp:post_id>{$id}</wp:post_id>
    <wp:post_date_gmt><![CDATA[2024-05-01 08:00:00]]></wp:post_date_gmt>
    <wp:status><![CDATA[{$status}]]></wp:status>
    <wp:post_type><![CDATA[{$type}]]></wp:post_type>
</item>
XML;
    }

    private function import(string $items, bool $downloadImages = true): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, $this->wxr($items));

        try {
            return app(WordPressImporter::class)->import($path, $this->admin, $downloadImages);
        } finally {
            @unlink($path);
        }
    }

    private function serve(string $path, string $type = 'png', int $width = 120, int $height = 80): string
    {
        $this->http->set(self::MEDIA.$path, $this->imageResponse($type, $width, $height));

        return self::MEDIA.$path;
    }

    private function runOf(array $results): ImportRun
    {
        return ImportRun::findOrFail($results['run_id']);
    }

    private function content(string $slug, string $model = Post::class): string
    {
        return (string) $model::where('slug', $slug)->value('content');
    }

    /** Runs queued DownloadContentImages jobs (including requeued ones) until none are left. */
    private function runQueuedImageJobs(): int
    {
        $ran = 0;
        $done = [];
        do {
            $pending = Queue::pushed(DownloadContentImages::class)->reject(fn ($job) => in_array(spl_object_id($job), $done, true));
            foreach ($pending as $job) {
                $done[] = spl_object_id($job);
                app()->call([$job, 'handle']);
                $ran++;
            }
        } while ($pending->isNotEmpty());

        return $ran;
    }

    private function releaseRebuildLock(): void
    {
        Cache::lock(UniqueLock::getKey(new RebuildAstroJob))->forceRelease();
    }

    // --- the flow ------------------------------------------------------------------

    public function test_images_on_the_exported_sites_hosts_are_downloaded_and_the_content_points_to_them(): void
    {
        $a = $this->serve('/2020/01/a.png');
        $b = $this->serve('/2020/01/b.jpg', 'jpeg');
        $results = $this->import($this->item('Post', "<p>Text</p><p><img src=\"$a\" alt=\"A\" /> <img src=\"$b\" /></p>"
            .'<p><img src="https://thirdparty.example/c.png" /><img src="https://old.example.evil.com/d.png" /></p>'));

        $content = $this->content('post');
        $this->assertDoesNotMatchRegularExpression('#files\.old\.example#', $content);
        $this->assertSame(2, preg_match_all('#<img src="/storage/\d+/[a-z0-9-]+\.(png|jpg)"#', $content));
        $this->assertStringContainsString('alt="A"', $content);
        // Third-party images stay where they are.
        $this->assertStringContainsString('<img src="https://thirdparty.example/c.png" />', $content);
        $this->assertStringContainsString('<img src="https://old.example.evil.com/d.png" />', $content);
        $this->assertNotContains('https://thirdparty.example/c.png', array_column($this->http->requests, 'url'));

        // In the importing user's library, with the conversions and the source.
        $media = Media::where('model_type', (new User)->getMorphClass())->where('model_id', $this->admin->id)->where('collection_name', 'library')->get();
        $this->assertCount(2, $media);
        $this->assertSame($a, $media[0]->getCustomProperty('source_url'));
        $this->assertSame(sha1('https://files.old.example/2020/01/a.png'), $media[0]->getCustomProperty('source_hash'));
        $this->assertTrue($media[0]->hasGeneratedConversion('thumb'));

        $run = $this->runOf($results);
        $this->assertSame(['status' => 'done', 'items_total' => 1, 'items_done' => 1, 'images_found' => 2, 'images_downloaded' => 2, 'images_reused' => 0, 'images_skipped' => 0],
            $run->only(['status', 'items_total', 'items_done', 'images_found', 'images_downloaded', 'images_reused', 'images_skipped']));
        $this->assertGreaterThan(0, $run->bytes_downloaded);
        $this->assertNotNull($run->finished_at);
    }

    public function test_the_allowlist_is_built_from_the_export(): void
    {
        $results = $this->import($this->item('Post', '<p>x</p>'), downloadImages: false);

        $this->assertSame(['old.example', 'blog.old.example', 'files.old.example'], $this->runOf($results)->allowed_hosts);
    }

    public function test_the_same_image_in_two_posts_is_stored_once_and_reused_later(): void
    {
        $a = $this->serve('/shared.png');
        $results = $this->import($this->item('One', "<p><img src=\"$a\" /></p>").$this->item('Two', "<p><img src=\"$a\" /><img src=\"".str_replace('https:', 'http:', $a).'" /></p>'));

        $this->assertSame(1, Media::count());
        $path = Media::first()->getUrl();
        $this->assertStringContainsString('src="'.parse_url($path, PHP_URL_PATH).'"', $this->content('one'));
        $this->assertSame(2, substr_count($this->content('two'), 'src="'.parse_url($path, PHP_URL_PATH).'"'));
        $this->assertCount(1, $this->http->requests);
        $run = $this->runOf($results);
        $this->assertSame([1, 2], [$run->images_downloaded, $run->images_reused]);

        // A later import that uses the image again reuses the file.
        $later = $this->import($this->item('Three', "<p><img src=\"$a\" /></p>"));
        $this->assertSame(1, Media::count());
        $this->assertSame(1, $this->runOf($later)->images_reused);
    }

    public function test_running_a_job_again_on_rewritten_content_changes_nothing(): void
    {
        $results = $this->import($this->item('Post', '<p><img src="'.$this->serve('/a.png').'" /></p>'));
        $content = $this->content('post');
        $requests = count($this->http->requests);

        (new DownloadContentImages($results['run_id'], 'post', Post::first()->id))->handle(app(ImportedImageStore::class));

        $this->assertSame($content, $this->content('post'));
        $this->assertSame($requests, count($this->http->requests));
        $this->assertSame(1, Media::count());
    }

    public function test_writing_back_makes_no_revision_and_keeps_updated_at(): void
    {
        Queue::fake();
        $this->import($this->item('Post', '<p><img src="'.$this->serve('/a.png').'" /></p>'));
        $post = Post::first();
        $updatedAt = $post->updated_at;

        $this->travel(2)->hours();
        $this->runQueuedImageJobs();

        $post->refresh();
        $this->assertStringContainsString('src="/storage/', $post->content);
        $this->assertTrue($post->updated_at->equalTo($updatedAt));
        $this->assertSame(0, $post->revisions()->count());
    }

    public function test_failed_images_are_recorded_with_their_reason_and_the_rest_still_work(): void
    {
        config(['import.max_image_bytes' => 300_000]);
        $good = $this->serve('/good.png');
        $this->http->set(self::MEDIA.'/missing.png', ['status' => 404]);
        $this->http->set(self::MEDIA.'/slow.png', ['error' => 'timeout']);
        $this->http->set(self::MEDIA.'/big.png', ['status' => 200, 'body' => str_repeat('x', 400_000)]);
        $this->http->set(self::MEDIA.'/page.png', ['status' => 200, 'body' => '<html>Error</html>']);
        $this->http->set(self::MEDIA.'/away.png', ['status' => 302, 'headers' => ['Location' => 'https://cdn.elsewhere.example/away.png']]);
        $images = collect(['missing', 'slow', 'big', 'page', 'away'])->map(fn ($n) => '<img src="'.self::MEDIA."/$n.png\" />")->implode('');

        $results = $this->import($this->item('Post', "<p>$images<img src=\"$good\" /></p>"));
        $this->assertSame(1, $this->runOf($results)->images_downloaded);

        $failures = collect($this->runOf($results)->failures)->pluck('reason', 'url')->all();
        $this->assertSame([
            self::MEDIA.'/missing.png' => 'HTTP 404',
            self::MEDIA.'/slow.png' => 'timeout',
            self::MEDIA.'/big.png' => 'too large',
            self::MEDIA.'/page.png' => 'not an image',
            self::MEDIA.'/away.png' => 'host not from the exported site',
        ], $failures);
        $this->assertSame('post "Post"', $this->runOf($results)->failures[0]['item']);
        $this->assertSame(5, $this->runOf($results)->images_skipped);
        $this->assertSame('done', $this->runOf($results)->status);

        // The failed ones keep their original URL; the good one is local.
        $content = $this->content('post');
        $this->assertStringContainsString('src="'.self::MEDIA.'/missing.png"', $content);
        $this->assertSame(1, substr_count($content, 'src="/storage/'));
    }

    public function test_a_blocked_address_on_an_allowed_host_is_recorded(): void
    {
        $this->fakeImageDownloads([], ['files.old.example' => ['10.1.2.3']]);
        $results = $this->import($this->item('Post', '<p><img src="'.self::MEDIA.'/a.png" /></p>'));

        $this->assertSame('blocked address', $this->runOf($results)->failures[0]['reason']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_an_edit_during_the_download_wins_and_the_rewrite_is_redone_on_it(): void
    {
        $url = $this->serve('/a.png');
        $results = $this->import($this->item('Post', "<p><img src=\"$url\" /></p>"), downloadImages: false);
        $post = Post::first();

        // While the image downloads, someone edits the post in the admin.
        $this->http->set($url, [...$this->imageResponse(), 'during' => fn () => DB::table('posts')->where('id', $post->id)->update(['content' => "<p>Edited</p><p><img src=\"$url\" /></p>"])]);
        $run = $this->runOf($results);
        $run->update(['status' => 'downloading', 'items_total' => 1]);
        (new DownloadContentImages($run->id, 'post', $post->id))->handle(app(ImportedImageStore::class));

        $content = $this->content('post');
        $this->assertStringStartsWith('<p>Edited</p>', $content);
        $this->assertStringContainsString('src="/storage/', $content);

        // An edit that removes the image: nothing left to rewrite, the edit stays as it is.
        $second = $this->serve('/b.png');
        DB::table('posts')->where('id', $post->id)->update(['content' => "<p><img src=\"$second\" /></p>"]);
        $this->http->set($second, [...$this->imageResponse(), 'during' => fn () => DB::table('posts')->where('id', $post->id)->update(['content' => '<p>No image any more</p>'])]);
        (new DownloadContentImages($run->id, 'post', $post->id))->handle(app(ImportedImageStore::class));
        $this->assertSame('<p>No image any more</p>', $this->content('post'));
    }

    public function test_a_link_around_an_image_to_the_full_size_file_is_unwrapped(): void
    {
        $thumb = $this->serve('/photo-300x200.jpg', 'jpeg');
        $content = '<p><a href="'.self::MEDIA.'/photo.jpg"><img src="'.$thumb.'" alt="Photo" /></a></p>'
            .'<p><a href="https://old.example/2020/01/photo-page/"><img src="'.$this->serve('/two.png').'" /></a></p>'
            .'<p><a href="https://thirdparty.example/big.jpg"><img src="'.$this->serve('/three.png').'" /></a></p>'
            .'<p><a href="'.self::MEDIA.'/four-full.png">Caption <img src="'.$this->serve('/four.png').'" /></a></p>';
        $this->import($this->item('Post', $content));

        $result = $this->content('post');
        // Unwrapped: only the image is left, local.
        $this->assertMatchesRegularExpression('#^<p><img src="/storage/\d+/photo-300x200\.jpg" alt="Photo" /></p>#', $result);
        // Kept: a link to a page, a link to another host, a link with text in it.
        $this->assertStringContainsString('<a href="https://old.example/2020/01/photo-page/"><img src="/storage/', $result);
        $this->assertStringContainsString('<a href="https://thirdparty.example/big.jpg"><img src="/storage/', $result);
        $this->assertStringContainsString('<a href="'.self::MEDIA.'/four-full.png">Caption <img src="/storage/', $result);
    }

    public function test_exactly_one_rebuild_is_queued_when_the_last_item_finishes(): void
    {
        Queue::fake();
        $this->import($this->item('One', '<p><img src="'.$this->serve('/a.png').'" /></p>')
            .$this->item('Two', '<p><img src="'.$this->serve('/b.png').'" /></p>')
            .$this->item('Three', '<p>No images</p>'));
        Queue::assertPushed(DownloadContentImages::class, 2);
        $importRebuilds = Queue::pushed(RebuildAstroJob::class)->count(); // from publishing the posts
        $this->releaseRebuildLock();

        $this->runQueuedImageJobs();

        Queue::assertPushed(RebuildAstroJob::class, $importRebuilds + 1);
        $this->assertSame('done', ImportRun::first()->status);

        // The last two finishing "at once": only one of them marks the run done and queues the rebuild.
        $this->releaseRebuildLock();
        $run = ImportRun::create(['user_id' => $this->admin->id, 'status' => 'downloading', 'items_total' => 2]);
        DB::table('import_runs')->where('id', $run->id)->update(['items_done' => 1]); // both have counted themselves
        $run->finishItem();
        // Free the uniqueness lock, so a second dispatch would really be pushed.
        $this->releaseRebuildLock();
        $run->finishItem();
        Queue::assertPushed(RebuildAstroJob::class, $importRebuilds + 2);
        $this->assertSame(['done', 3], [$run->fresh()->status, $run->fresh()->items_done]);
    }

    public function test_a_job_out_of_time_queues_itself_for_the_remaining_images(): void
    {
        Queue::fake([RebuildAstroJob::class]);
        // A budget that is used up after the first image of each job.
        config(['import.job_time_budget' => (int) config('import.timeout') + 15]);
        $images = collect(range(1, 3))->map(fn ($i) => '<img src="'.$this->serve("/$i.png").'" />')->implode('');

        $results = $this->import($this->item('Post', "<p>$images</p>"));

        $run = $this->runOf($results);
        $this->assertSame(['done', 3, 1], [$run->status, $run->images_downloaded, $run->items_done]);
        $this->assertSame(3, substr_count($this->content('post'), 'src="/storage/'));
        Queue::assertPushed(RebuildAstroJob::class, fn () => true);
    }

    // --- limits ------------------------------------------------------------------------

    public function test_the_per_item_limit(): void
    {
        config(['import.max_images_per_item' => 2]);
        $images = collect(range(1, 3))->map(fn ($i) => '<img src="'.$this->serve("/$i.png").'" />')->implode('');
        $results = $this->import($this->item('Post', "<p>$images</p>"));

        $run = $this->runOf($results);
        $this->assertSame([2, 1], [$run->images_downloaded, $run->images_skipped]);
        $this->assertSame('item limit: more than 2 images', $run->failures[0]['reason']);
        $this->assertSame(self::MEDIA.'/3.png', $run->failures[0]['url']);
    }

    public function test_the_per_run_limit(): void
    {
        config(['import.max_images_per_run' => 2]);
        $results = $this->import(
            $this->item('One', '<p><img src="'.$this->serve('/1.png').'" /><img src="'.$this->serve('/2.png').'" /></p>')
            .$this->item('Two', '<p><img src="'.$this->serve('/3.png').'" /></p>'));

        $run = $this->runOf($results);
        $this->assertSame([2, 1], [$run->images_downloaded, $run->images_skipped]);
        $this->assertSame('run limit: 2 images downloaded', $run->failures[0]['reason']);
    }

    public function test_the_total_bytes_limit(): void
    {
        $first = $this->serve('/1.png', 'png', 200, 200);
        $size = strlen($this->imageBytes('png', 200, 200)); // GD output is the same each time
        config(['import.max_total_bytes' => $size + 10]); // room for one image, not two
        $results = $this->import($this->item('Post', '<p><img src="'.$first.'" /><img src="'.$this->serve('/2.png', 'png', 200, 200).'" /></p>'));

        $run = $this->runOf($results);
        $this->assertSame(1, $run->images_downloaded);
        $this->assertStringStartsWith('run limit: ', $run->failures[0]['reason']);
        $this->assertLessThanOrEqual($size + 10, $run->bytes_downloaded);
    }

    public function test_low_disk_space_stops_downloads(): void
    {
        config(['import.min_free_disk_bytes' => PHP_INT_MAX]);
        $results = $this->import($this->item('Post', '<p><img src="'.$this->serve('/1.png').'" /></p>'));

        $run = $this->runOf($results);
        $this->assertSame([0, 1], [$run->images_downloaded, $run->images_skipped]);
        $this->assertSame('low disk space', $run->failures[0]['reason']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_at_most_200_failures_are_kept(): void
    {
        $run = ImportRun::create(['user_id' => $this->admin->id, 'status' => 'downloading']);
        foreach (range(1, 205) as $i) {
            $run->recordFailure('post "X"', "https://x/$i.png", 'HTTP 404');
        }

        $run->refresh();
        $this->assertCount(200, $run->failures);
        $this->assertSame(205, $run->images_skipped);
    }

    // --- without images, status, sanitiser, API ----------------------------------------------

    public function test_without_download_images_nothing_is_queued_and_the_run_is_done(): void
    {
        Queue::fake([DownloadContentImages::class]);
        $url = self::MEDIA.'/a.png';
        $results = $this->import($this->item('Post', "<p><img src=\"$url\" /></p>"), downloadImages: false);

        Queue::assertNotPushed(DownloadContentImages::class);
        $this->assertSame(['done', 0, 0], [$this->runOf($results)->status, $this->runOf($results)->items_total, $this->runOf($results)->images_found]);
        $this->assertStringContainsString("src=\"$url\"", $this->content('post'));
        $this->assertSame([], $this->http->requests);
    }

    public function test_a_file_that_is_not_an_export_marks_the_run_failed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, '<?xml version="1.0"?><notes><note>Hi</note></notes>');
        $results = app(WordPressImporter::class)->import($path, $this->admin, true);
        @unlink($path);

        $this->assertSame('failed', $this->runOf($results)->status);
    }

    public function test_the_upload_form_option_and_the_status_endpoint(): void
    {
        Queue::fake([DownloadContentImages::class]);
        $file = UploadedFile::fake()->createWithContent('export.xml', $this->wxr($this->item('Post', '<p><img src="'.$this->serve('/a.png').'" /></p>')));

        $this->actingAs($this->admin)->post('/import', ['file' => $file, 'download_images' => '1'])->assertRedirect(route('import.result'));
        $run = ImportRun::latest('id')->first();
        $this->assertSame('downloading', $run->status);
        Queue::assertPushed(DownloadContentImages::class, 1);

        $run->recordFailure('post "Post"', self::MEDIA.'/x.png', 'HTTP 404');
        $this->actingAs($this->admin)->getJson("/import/runs/{$run->id}/status")
            ->assertOk()
            ->assertJson(['id' => $run->id, 'status' => 'downloading', 'items_total' => 1, 'items_done' => 0, 'images_found' => 1, 'images_skipped' => 1, 'failures_recorded' => 1])
            ->assertJsonPath('failures.0.reason', 'HTTP 404');

        $this->actingAs($this->admin)->getJson('/import/runs/999999/status')->assertNotFound();
        $this->actingAs(User::factory()->editor()->create())->getJson("/import/runs/{$run->id}/status")->assertForbidden();
        auth()->logout();
        $this->get("/import/runs/{$run->id}/status")->assertRedirect('/login');

        // The option defaults to off.
        $plain = UploadedFile::fake()->createWithContent('export.xml', $this->wxr($this->item('Other', '<p><img src="'.self::MEDIA.'/b.png" /></p>')));
        $this->actingAs($this->admin)->post('/import', ['file' => $plain]);
        $this->assertSame('done', ImportRun::latest('id')->first()->status);
        Queue::assertPushed(DownloadContentImages::class, 1);
    }

    public function test_the_status_endpoint_lists_at_most_50_failures(): void
    {
        $run = ImportRun::create(['user_id' => $this->admin->id, 'status' => 'done']);
        foreach (range(1, 60) as $i) {
            $run->recordFailure('post "X"', "https://x/$i.png", 'HTTP 404');
        }

        $this->actingAs($this->admin)->getJson("/import/runs/{$run->id}/status")
            ->assertJsonCount(50, 'failures')
            ->assertJsonPath('failures_recorded', 60)
            ->assertJsonPath('failures.0.url', 'https://x/1.png');
    }

    public function test_rewritten_content_is_a_fixed_point_of_the_sanitiser(): void
    {
        $content = '<p>Text &amp; more</p><p><a href="'.self::MEDIA.'/full.jpg"><img src="'.$this->serve('/a-300x200.jpg', 'jpeg').'" alt="A &amp; B" width="300" height="200" /></a></p>'
            .'<p style="text-align: center"><img src="'.$this->serve('/b.png').'?w=604&amp;h=2" title="t" /></p><ul><li>x</li></ul>';
        $this->http->set(self::MEDIA.'/b.png?w=604&h=2', $this->imageResponse());
        $this->import($this->item('Post', $content).$this->item('About', $content, 'page'));

        foreach ([$this->content('post'), $this->content('about', Page::class)] as $stored) {
            $this->assertStringNotContainsString('files.old.example', $stored);
            $this->assertSame($stored, app(ContentSanitizer::class)->sanitize($stored));
        }
        $this->artisan('content:sanitize', ['--dry-run' => true])
            ->expectsOutput('0 posts would change.')
            ->expectsOutput('0 pages would change.')
            ->assertSuccessful();
    }

    public function test_the_sanitiser_keeps_relative_storage_images(): void
    {
        $sanitizer = app(ContentSanitizer::class);
        $this->assertSame('<img src="/storage/12/photo.jpg" alt="x" />', $sanitizer->sanitize('<img src="/storage/12/photo.jpg" alt="x">'));
        // Unsafe image sources are still removed.
        $this->assertSame('<img />', $sanitizer->sanitize('<img src="javascript:alert(1)">'));
        $this->assertSame('<img />', $sanitizer->sanitize('<img src="data:image/svg+xml;base64,PHN2Zz4=">'));
    }

    public function test_the_public_api_makes_storage_images_absolute(): void
    {
        config(['app.url' => 'https://admin.example.com']);
        $this->import($this->item('Post', '<p><a href="/storage/9/file.pdf">PDF</a> <img src="'.$this->serve('/a.png').'" /> <img src="https://thirdparty.example/c.png" /></p>')
            .$this->item('About', '<p><img src="'.$this->serve('/b.png').'" /></p>', 'page'));
        $stored = $this->content('post');
        $path = parse_url(Media::first()->getUrl(), PHP_URL_PATH);

        $post = $this->getJson('/api/public/posts/post')->assertOk()->json();
        $expected = str_replace('<img src="/storage/', '<img src="https://admin.example.com/storage/', $stored);
        $this->assertSame($expected, $post['content']);
        // Only img src: the link to a /storage/ file and third-party images are untouched.
        $this->assertStringContainsString('<a href="/storage/9/file.pdf">', $post['content']);
        $this->assertStringContainsString('<img src="https://thirdparty.example/c.png" />', $post['content']);
        // The og:image fallback (first image in the content) is absolute too.
        $this->assertSame('https://admin.example.com'.$path, $post['og_image']);
        // The stored content stays relative.
        $this->assertSame($stored, $this->content('post'));

        $page = $this->getJson('/api/public/pages/about')->json();
        $this->assertStringContainsString('<img src="https://admin.example.com/storage/', $page['content']);
        $this->assertStringStartsWith('https://admin.example.com/storage/', $page['og_image']);
    }

    public function test_block_html_images_are_made_absolute_and_the_change_is_idempotent(): void
    {
        config(['app.url' => 'https://admin.example.com']);
        Page::create(['author_id' => $this->admin->id, 'title' => 'Blocks', 'slug' => 'blocks', 'status' => 'published', 'published_at' => now()->subDay(),
            'blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'a', 'type' => 'text', 'data' => ['html' => '<p><img src="/storage/1/a.png" /> <img src="https://admin.example.com/storage/2/b.png" /></p>']],
                ['id' => 'b', 'type' => 'image', 'data' => ['image' => '/storage/3/c.png', 'alt' => '']],
            ]]]);

        $blocks = $this->getJson('/api/public/pages/blocks')->json('blocks');

        $this->assertSame('<p><img src="https://admin.example.com/storage/1/a.png" /> <img src="https://admin.example.com/storage/2/b.png" /></p>', $blocks[0]['data']['html']);
        // Block image fields are left as they were (the public site resolves them itself).
        $this->assertSame('/storage/3/c.png', $blocks[1]['data']['image']);
    }

    public function test_a_protocol_relative_image_in_an_import_is_downloaded(): void
    {
        $this->serve('/2020/01/relative.png');
        $results = $this->import($this->item('Post', '<p><img src="//files.old.example/2020/01/relative.png" alt="R"></p>'
            ."<p><img src='//thirdparty.example/c.png'></p>"));

        $content = $this->content('post');
        $this->assertMatchesRegularExpression('#<img src="/storage/\\d+/relative\\.png" alt="R" />#', $content);
        // A third-party one is kept, as https (the sanitiser would otherwise drop the "//" source).
        $this->assertStringContainsString('<img src="https://thirdparty.example/c.png" />', $content);
        $this->assertSame([1, 1], [$this->runOf($results)->images_found, $this->runOf($results)->images_downloaded]);
        $this->assertSame($content, app(ContentSanitizer::class)->sanitize($content));
    }
}
