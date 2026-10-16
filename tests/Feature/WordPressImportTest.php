<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\WordPressImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WordPressImportTest extends TestCase
{
    use RefreshDatabase;

    private function wxr(string $items): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <title>Test blog</title>
    <wp:wxr_version>1.2</wp:wxr_version>
    {$items}
</channel>
</rss>
XML;
    }

    private function item(string $title, string $type, string $status, string $content = '', string $extra = ''): string
    {
        return <<<XML
<item>
    <title><![CDATA[{$title}]]></title>
    <content:encoded><![CDATA[{$content}]]></content:encoded>
    <wp:post_id>{$this->nextId()}</wp:post_id>
    <wp:post_date><![CDATA[2024-05-01 10:00:00]]></wp:post_date>
    <wp:post_date_gmt><![CDATA[2024-05-01 08:00:00]]></wp:post_date_gmt>
    <wp:status><![CDATA[{$status}]]></wp:status>
    <wp:post_type><![CDATA[{$type}]]></wp:post_type>
    {$extra}
</item>
XML;
    }

    private int $id = 100;

    private function nextId(): int
    {
        return $this->id++;
    }

    private function importString(string $xml, User $user): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, $xml);

        try {
            return app(WordPressImporter::class)->import($path, $user);
        } finally {
            @unlink($path);
        }
    }

    public function test_imports_posts_and_pages_with_status_mapping_and_skips(): void
    {
        $user = User::factory()->create();

        $results = $this->importString($this->wxr(
            $this->item('Hello World', 'post', 'publish', '<p>Hi</p>').
            $this->item('Work in progress', 'post', 'draft', 'Draft body').
            $this->item('About us', 'page', 'publish', '<p>About</p>').
            $this->item('Secret', 'post', 'private').
            $this->item('Logo', 'attachment', 'inherit').
            $this->item('Menu', 'nav_menu_item', 'publish')
        ), $user);

        $this->assertSame(2, $results['imported_posts']);
        $this->assertSame(1, $results['imported_pages']);
        $this->assertCount(1, $results['skipped']);
        $this->assertSame('Secret', $results['skipped'][0]['title']);
        $this->assertSame([], $results['errors']);

        $published = Post::where('slug', 'hello-world')->firstOrFail();
        $this->assertSame('published', $published->status);
        $this->assertSame($user->id, $published->author_id);
        $this->assertSame('2024-05-01 08:00:00', $published->published_at->utc()->toDateTimeString());

        $draft = Post::where('slug', 'work-in-progress')->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->published_at);

        $this->assertTrue(Page::where('slug', 'about-us')->where('status', 'published')->exists());
    }

    public function test_pages_are_detected_in_real_world_export_formats(): void
    {
        $user = User::factory()->create();

        // Current WordPress exports use an https:// namespace URI; older ones use http://.
        // Also covers whitespace around CDATA from hand-edited/pretty-printed files.
        $https = str_replace('http://wordpress.org/export/', 'https://wordpress.org/export/', $this->wxr(
            $this->item('Https page', 'page', 'publish', 'Body').
            $this->item('Https post', 'post', 'publish', 'Body')
        ));
        $whitespace = str_replace(
            ['<![CDATA[page]]>', '<![CDATA[publish]]>'],
            ["\n        <![CDATA[page]]>\n    ", ' <![CDATA[publish]]> '],
            $this->wxr($this->item('Spaced page', 'page', 'publish', 'Body'))
        );

        $first = $this->importString($https, $user);
        $second = $this->importString($whitespace, $user);

        $this->assertSame(1, $first['imported_pages']);
        $this->assertSame(['page' => 1, 'post' => 1], $first['found']);
        $this->assertSame(1, $second['imported_pages']);
        $this->assertSame(['https-page', 'spaced-page'], Page::orderBy('slug')->pluck('slug')->all());
    }

    public function test_found_counts_explain_a_posts_only_file(): void
    {
        Log::spy();
        $user = User::factory()->create();

        $results = $this->importString($this->wxr(
            $this->item('Only post', 'post', 'publish', 'Body').
            $this->item('Logo', 'attachment', 'inherit').
            $this->item('Menu', 'nav_menu_item', 'publish')
        ), $user);

        $this->assertSame(0, $results['imported_pages']);
        $this->assertSame(['post' => 1, 'attachment' => 1, 'nav_menu_item' => 1], $results['found']);
        $this->assertArrayNotHasKey('page', $results['found']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'WordPress import finished'
                && $context['imported_pages'] === 0
                && $context['found']['post'] === 1)
            ->once();
    }

    public function test_running_the_same_import_twice_skips_duplicates(): void
    {
        $user = User::factory()->create();
        $xml = $this->wxr($this->item('Hello World', 'post', 'publish', 'Hi'));

        $this->importString($xml, $user);
        $second = $this->importString($xml, $user);

        $this->assertSame(0, $second['imported_posts']);
        $this->assertCount(1, $second['skipped']);
        $this->assertSame(1, Post::count());
    }

    public function test_content_is_cleaned(): void
    {
        $user = User::factory()->create();
        $content = <<<'HTML'
<!-- wp:paragraph -->
<p onclick="steal()">Intro with <a href="javascript:alert(1)">bad link</a> and <a href="https://example.com" style="color:red">good link</a>. See note [1].</p>
<!-- /wp:paragraph -->
[gallery ids="1,2,3"]
[caption id="x"]<img src="https://example.com/a.jpg" alt="A" onerror="x()">Caption text[/caption]
<div class="wrapper"><span>Kept text</span></div>
<script>alert('xss')</script>
<iframe src="https://evil.example"></iframe>

Classic paragraph one
with a line break.

Classic paragraph two.
HTML;

        $this->importString($this->wxr($this->item('Clean me', 'post', 'publish', $content)), $user);
        $html = Post::firstOrFail()->content;

        // Dangerous markup removed
        foreach (['onclick', 'javascript:', 'onerror', '<script', 'alert(', '<iframe', 'style=', '<div', '<span', 'wp:paragraph', '[gallery', '[caption', '[/caption]'] as $bad) {
            $this->assertStringNotContainsString($bad, $html);
        }

        // Allowed content kept
        $this->assertStringContainsString('<a href="https://example.com">good link</a>', $html);
        $this->assertStringContainsString('<img src="https://example.com/a.jpg" alt="A" />', $html);
        $this->assertStringContainsString('Caption text', $html);
        $this->assertStringContainsString('Kept text', $html);
        $this->assertStringContainsString('[1]', $html); // non-shortcode brackets survive
        $flat = str_replace("\n", '', $html);
        $this->assertStringContainsString('<p>Classic paragraph one<br />with a line break.</p>', $flat);
        $this->assertStringContainsString('<p>Classic paragraph two.</p>', $flat);
        $this->assertStringNotContainsString('<p></p>', $flat);
    }

    public function test_long_content_is_not_truncated(): void
    {
        $user = User::factory()->create();
        $long = '<p>'.str_repeat('word ', 10000).'end-marker</p>';

        $this->importString($this->wxr($this->item('Long', 'post', 'publish', $long)), $user);

        $this->assertStringContainsString('end-marker', Post::firstOrFail()->content);
    }

    public function test_malformed_and_non_wordpress_files_report_errors_without_crashing(): void
    {
        $user = User::factory()->create();

        $garbage = $this->importString('this is not xml at all', $user);
        $this->assertNotEmpty($garbage['errors']);
        $this->assertSame(0, $garbage['imported_posts']);

        $notWp = $this->importString('<?xml version="1.0"?><rss><channel><item><title>x</title></item></channel></rss>', $user);
        $this->assertStringContainsString('WordPress', $notWp['errors'][0]);

        // Truncated export: the complete first item is still imported, with a warning.
        $truncated = substr($this->wxr(
            $this->item('Complete post', 'post', 'publish', 'ok').
            $this->item('Cut off post', 'post', 'publish', 'this item is cut off half way')
        ), 0, -120);
        $result = $this->importString($truncated, $user);

        $this->assertTrue(Post::where('slug', 'complete-post')->exists());
        $this->assertNotEmpty($result['errors']);
    }

    public function test_featured_image_failures_are_silently_skipped(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('Not found', 404)]);
        $user = User::factory()->create();

        $xml = $this->wxr(
            $this->item('Photo', 'attachment', 'inherit', '', '<wp:attachment_url><![CDATA[https://old.example/photo.jpg]]></wp:attachment_url>').
            $this->item('With image', 'post', 'publish', 'Body', '<wp:postmeta><wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key><wp:meta_value><![CDATA[100]]></wp:meta_value></wp:postmeta>')
        );

        $results = $this->importString($xml, $user);

        $this->assertSame(1, $results['imported_posts']);
        $this->assertSame([], $results['errors']);
        $this->assertCount(0, Post::firstOrFail()->getMedia('featured'));
        Http::assertSent(fn ($request) => $request->url() === 'https://old.example/photo.jpg');
    }

    public function test_featured_image_is_downloaded_and_converted(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Requires the PHP GD extension for image conversions.');
        }

        Storage::fake('public');
        $png = UploadedFile::fake()->image('photo.png', 800, 600)->getContent();
        Http::fake(['*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $user = User::factory()->create();

        $xml = $this->wxr(
            $this->item('Photo', 'attachment', 'inherit', '', '<wp:attachment_url><![CDATA[https://old.example/photo.png]]></wp:attachment_url>').
            $this->item('With image', 'post', 'publish', 'Body', '<wp:postmeta><wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key><wp:meta_value><![CDATA[100]]></wp:meta_value></wp:postmeta>')
        );

        $this->importString($xml, $user);

        $media = Post::firstOrFail()->getFirstMedia('featured');
        $this->assertNotNull($media);
        $this->assertTrue($media->hasGeneratedConversion('thumb'));
        $this->assertStringEndsWith('.webp', $media->getPath('thumb'));
    }

    public function test_import_routes_upload_and_show_results(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/import/result')->assertRedirect(route('import.create'));
        $this->actingAs($user)->post('/import', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text'),
        ])->assertSessionHasErrors('file');

        $file = UploadedFile::fake()->createWithContent(
            'export.xml',
            $this->wxr($this->item('Via upload', 'post', 'publish', 'Body'))
        );

        $this->actingAs($user)->post('/import', ['file' => $file])
            ->assertRedirect(route('import.result'));

        $this->actingAs($user)->get('/import/result')
            ->assertInertia(fn ($page) => $page
                ->component('Import/Result')
                ->where('results.imported_posts', 1));
    }
}
