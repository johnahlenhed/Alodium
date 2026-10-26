<?php

namespace Tests\Feature;

use App\Blocks\BlockRegistry;
use App\Jobs\RebuildAstroJob;
use App\Models\Page;
use App\Models\Post;
use App\Models\Revision;
use App\Models\User;
use App\Services\WordPressImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PageBlocksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        config(['app.url' => 'https://admin.example.com']);
    }

    /** One valid block of every type. */
    private static function validBlocks(): array
    {
        return [
            ['id' => 'hero-1', 'type' => 'hero', 'data' => ['heading' => 'Welcome', 'subheading' => 'To the site', 'image' => '/storage/1/hero.jpg', 'button_label' => 'Contact', 'button_url' => '/contact']],
            ['id' => 'text-1', 'type' => 'text', 'data' => ['html' => '<p>Hello <strong>world</strong>.</p><p>Second</p>']],
            ['id' => 'image-1', 'type' => 'image', 'data' => ['image' => 'https://cdn.example.com/photo.jpg', 'alt' => 'Photo', 'caption' => 'A caption']],
            ['id' => 'columns-1', 'type' => 'columns', 'data' => ['columns' => [
                ['html' => '<p>Left</p>', 'image' => null, 'alt' => null],
                ['html' => '<p>Right</p>', 'image' => '/storage/2/col.jpg', 'alt' => 'Col'],
            ]]],
            ['id' => 'button-1', 'type' => 'button', 'data' => ['label' => 'Buy', 'url' => 'https://shop.example.com', 'style' => 'secondary']],
            ['id' => 'form-1', 'type' => 'contact_form', 'data' => ['heading' => 'Get in touch', 'intro' => 'We reply fast']],
        ];
    }

    private function page(array $attributes = []): Page
    {
        return Page::create([
            'author_id' => $this->admin->id,
            'title' => 'About',
            'slug' => 'about',
            'content' => '<p>Legacy content</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            ...$attributes,
        ]);
    }

    private function blockPage(?array $blocks = null, array $attributes = []): Page
    {
        return $this->page(['blocks' => ['version' => 1, 'blocks' => $blocks ?? self::validBlocks()], ...$attributes]);
    }

    private function save(Page $page, array $data): TestResponse
    {
        return $this->actingAs($this->admin)->put("/pages/{$page->id}", [
            'title' => $page->title,
            'status' => $page->status,
            ...$data,
        ]);
    }

    // ---------------------------------------------------------------- storage

    public function test_migration_adds_nullable_blocks_columns_and_keeps_content(): void
    {
        foreach (['pages', 'revisions'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'blocks'));
            $this->assertTrue(Schema::hasColumn($table, 'content'));
        }
    }

    public function test_blocks_are_cast_to_an_array_and_has_blocks_distinguishes_page_kinds(): void
    {
        $blockPage = $this->blockPage();
        $legacy = $this->page(['slug' => 'legacy']);
        $empty = $this->page(['slug' => 'empty', 'blocks' => ['version' => 1, 'blocks' => []]]);

        $this->assertSame(1, $blockPage->fresh()->blocks['version']);
        $this->assertSame('hero', $blockPage->fresh()->blocks['blocks'][0]['type']);
        $this->assertTrue($blockPage->fresh()->hasBlocks());
        $this->assertFalse($legacy->fresh()->hasBlocks());
        $this->assertNull($legacy->fresh()->blocks);
        $this->assertTrue($empty->fresh()->hasBlocks()); // an empty list is still a block page
    }

    public function test_blocks_or_legacy(): void
    {
        $this->assertSame(self::validBlocks(), $this->blockPage()->blocksOrLegacy());

        $legacy = $this->page(['slug' => 'legacy'])->blocksOrLegacy();
        $this->assertCount(1, $legacy);
        $this->assertSame('text', $legacy[0]['type']);
        $this->assertSame(['html' => '<p>Legacy content</p>'], $legacy[0]['data']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $legacy[0]['id']);

        $this->assertSame([], $this->page(['slug' => 'empty', 'content' => ''])->blocksOrLegacy());
        $this->assertSame([], $this->page(['slug' => 'null', 'content' => null])->blocksOrLegacy());
    }

    public function test_plain_text_for_both_page_kinds(): void
    {
        $this->assertSame('Welcome To the site Hello world. Second Left Right Get in touch We reply fast', $this->blockPage()->plainText());
        $this->assertSame('Legacy content', $this->page(['slug' => 'legacy'])->plainText());
    }

    // -------------------------------------------------------------- validation

    public function test_a_valid_example_of_every_block_type_is_accepted_and_stored(): void
    {
        $page = $this->page();

        $this->save($page, ['blocks' => self::validBlocks()])->assertSessionHasNoErrors();

        $stored = $page->fresh()->blocks;
        $this->assertSame(1, $stored['version']);
        $this->assertSame(['hero', 'text', 'image', 'columns', 'button', 'contact_form'], array_column($stored['blocks'], 'type'));
        $this->assertSame('Welcome', $stored['blocks'][0]['data']['heading']);
        $this->assertSame('secondary', $stored['blocks'][4]['data']['style']);
        $this->assertCount(2, $stored['blocks'][3]['data']['columns']);
    }

    public static function missingRequiredFields(): array
    {
        return [
            'hero heading' => ['hero', ['subheading' => 'x'], 'heading'],
            'text html' => ['text', [], 'html'],
            'image image' => ['image', ['alt' => 'x'], 'image'],
            'columns columns' => ['columns', [], 'columns'],
            'columns needs exactly two' => ['columns', ['columns' => [['html' => '<p>one</p>']]], 'columns'],
            'button label' => ['button', ['url' => '/x'], 'label'],
            'button url' => ['button', ['label' => 'Go'], 'url'],
        ];
    }

    #[DataProvider('missingRequiredFields')]
    public function test_a_missing_required_field_is_rejected(string $type, array $data, string $field): void
    {
        $this->save($this->page(), ['blocks' => [
            ['id' => 'ok', 'type' => 'text', 'data' => ['html' => '<p>fine</p>']],
            ['id' => 'bad', 'type' => $type, 'data' => $data],
        ]])->assertSessionHasErrors("blocks.1.data.{$field}");
    }

    public function test_field_limits_are_enforced_including_contact_form(): void
    {
        $this->save($this->page(), ['blocks' => [
            ['type' => 'contact_form', 'data' => ['heading' => str_repeat('x', 201), 'intro' => str_repeat('x', 501)]],
            ['type' => 'button', 'data' => ['label' => 'Go', 'url' => '/x', 'style' => 'huge']],
        ]])->assertSessionHasErrors(['blocks.0.data.heading', 'blocks.0.data.intro', 'blocks.1.data.style']);
    }

    public function test_unknown_block_type_is_rejected_with_its_name(): void
    {
        $this->save($this->page(), ['blocks' => [['type' => 'faq', 'data' => []]]])
            ->assertSessionHasErrors(['blocks.0.type' => 'Unknown block type "faq".']);
    }

    public function test_unknown_data_keys_are_dropped(): void
    {
        $page = $this->page();

        $this->save($page, ['blocks' => [
            ['id' => 'h', 'type' => 'hero', 'data' => ['heading' => 'Hi', 'onclick' => 'x()', 'html' => '<script>x</script>']],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(
            ['heading' => 'Hi', 'subheading' => null, 'image' => null, 'button_label' => null, 'button_url' => null],
            $page->fresh()->blocks['blocks'][0]['data'],
        );
    }

    public function test_more_than_50_blocks_are_rejected(): void
    {
        $blocks = array_fill(0, 51, ['type' => 'text', 'data' => ['html' => '<p>x</p>']]);

        $this->save($this->page(), ['blocks' => $blocks])->assertSessionHasErrors('blocks');
        $this->save($this->page(['slug' => 'ok']), ['blocks' => array_slice($blocks, 0, 50)])->assertSessionHasNoErrors();
    }

    public function test_duplicate_ids_are_rejected_and_invalid_ids_too(): void
    {
        $this->save($this->page(), ['blocks' => [
            ['id' => 'same', 'type' => 'text', 'data' => ['html' => 'a']],
            ['id' => 'same', 'type' => 'text', 'data' => ['html' => 'b']],
            ['id' => 'has spaces!', 'type' => 'text', 'data' => ['html' => 'c']],
            ['id' => str_repeat('a', 37), 'type' => 'text', 'data' => ['html' => 'd']],
        ]])->assertSessionHasErrors(['blocks.1.id', 'blocks.2.id', 'blocks.3.id'])
            ->assertSessionDoesntHaveErrors('blocks.0.id');
    }

    public function test_a_missing_id_is_generated(): void
    {
        $page = $this->page();

        $this->save($page, ['blocks' => [
            ['type' => 'text', 'data' => ['html' => '<p>a</p>']],
            ['id' => 'kept-id', 'type' => 'text', 'data' => ['html' => '<p>b</p>']],
        ]])->assertSessionHasNoErrors();

        $blocks = $page->fresh()->blocks['blocks'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $blocks[0]['id']);
        $this->assertSame('kept-id', $blocks[1]['id']);
    }

    public static function links(): array
    {
        return [
            'javascript' => ['javascript:alert(1)', false],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)', false],
            'javascript with tab' => ["java\tscript:alert(1)", false],
            'data' => ['data:text/html,<script>alert(1)</script>', false],
            'vbscript' => ['vbscript:msgbox(1)', false],
            'protocol-relative' => ['//evil.example.com', false],
            'relative path' => ['/contact', true],
            'relative with query' => ['/blog?page=2#top', true],
            'https' => ['https://example.com/x', true],
            'mailto' => ['mailto:hello@example.com', true],
            'tel' => ['tel:+46700000000', true],
        ];
    }

    #[DataProvider('links')]
    public function test_links_are_checked(string $url, bool $allowed): void
    {
        $response = $this->save($this->page(), ['blocks' => [['type' => 'button', 'data' => ['label' => 'Go', 'url' => $url]]]]);

        $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('blocks.0.data.url');
    }

    public function test_media_urls_must_be_http_or_storage_paths(): void
    {
        foreach (['javascript:alert(1)', 'data:image/png;base64,xx', '/private/a.jpg', 'ftp://x/a.jpg'] as $bad) {
            $this->save($this->page(['slug' => 'p'.md5($bad)]), ['blocks' => [['type' => 'image', 'data' => ['image' => $bad]]]])
                ->assertSessionHasErrors('blocks.0.data.image');
        }
        foreach (['/storage/1/a.jpg', 'https://cdn.example.com/a.jpg'] as $good) {
            $this->save($this->page(['slug' => 'p'.md5($good)]), ['blocks' => [['type' => 'image', 'data' => ['image' => $good]]]])
                ->assertSessionHasNoErrors();
        }
    }

    public function test_block_html_is_sanitised_and_plain_text_is_stored_as_given(): void
    {
        $page = $this->page();

        $this->save($page, ['blocks' => [
            ['type' => 'text', 'data' => ['html' => '<p style="text-align: center" onclick="x()">Hi</p><script>alert(1)</script>']],
            ['type' => 'columns', 'data' => ['columns' => [
                ['html' => '<a href="javascript:alert(1)">x</a>'],
                ['html' => '<p>ok</p>'],
            ]]],
            ['type' => 'hero', 'data' => ['heading' => 'Tom & Jerry <b>not html</b>']],
        ]])->assertSessionHasNoErrors();

        $blocks = $page->fresh()->blocks['blocks'];
        $this->assertSame('<p style="text-align: center">Hi</p>', $blocks[0]['data']['html']);
        $this->assertSame('<a>x</a>', $blocks[1]['data']['columns'][0]['html']);
        // Plain text is not turned into HTML or stripped; the renderer must escape it.
        $this->assertSame('Tom & Jerry <b>not html</b>', $blocks[2]['data']['heading']);
    }

    // ------------------------------------------------------- store and update

    public function test_store_and_update_without_a_blocks_key_leave_blocks_untouched(): void
    {
        $this->actingAs($this->admin)->post('/pages', ['title' => 'New', 'status' => 'draft'])->assertSessionHasNoErrors();
        $this->assertNull(Page::where('title', 'New')->value('blocks'));

        $page = $this->blockPage();
        $this->save($page, ['title' => 'Renamed', 'meta_title' => 'SEO'])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $page->fresh()->title);
        $this->assertSame(self::validBlocks(), $page->fresh()->blockList());
    }

    public function test_store_with_blocks_creates_a_block_page(): void
    {
        $this->actingAs($this->admin)->post('/pages', [
            'title' => 'Landing', 'status' => 'draft',
            'blocks' => [['type' => 'hero', 'data' => ['heading' => 'Hi']]],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Page::where('title', 'Landing')->firstOrFail()->hasBlocks());
    }

    public function test_blocks_null_turns_a_block_page_back_into_a_legacy_page(): void
    {
        $page = $this->blockPage();

        $this->save($page, ['blocks' => null])->assertSessionHasNoErrors();

        $this->assertFalse($page->fresh()->hasBlocks());
        $this->assertSame('<p>Legacy content</p>', $page->fresh()->content);
    }

    public function test_an_empty_blocks_list_is_stored_as_a_block_page(): void
    {
        $page = $this->page();

        $this->save($page, ['blocks' => []])->assertSessionHasNoErrors();

        $this->assertSame(['version' => 1, 'blocks' => []], $page->fresh()->blocks);
    }

    // ----------------------------------------------------------------- revisions

    public function test_changing_blocks_creates_a_revision_with_the_previous_blocks(): void
    {
        $page = $this->blockPage();
        $old = $page->blocks;

        $this->save($page, ['blocks' => [['id' => 'only', 'type' => 'text', 'data' => ['html' => '<p>New version</p>']]]]);

        $this->assertSame(1, $page->revisions()->count());
        $this->assertEquals($old, $page->revisions()->first()->blocks);
    }

    public function test_saving_unchanged_blocks_creates_no_revision(): void
    {
        $page = $this->page();
        $this->save($page, ['blocks' => self::validBlocks()]); // legacy -> blocks: one revision (the legacy version)
        $this->assertSame(1, $page->revisions()->count());

        $this->save($page, ['blocks' => self::validBlocks()]); // identical
        $this->save($page, ['blocks' => $page->fresh()->blockList()]); // identical, as stored

        $this->assertSame(1, $page->revisions()->count());
    }

    public function test_restore_brings_blocks_back_for_a_block_page_revision(): void
    {
        $page = $this->blockPage();
        $this->save($page, ['blocks' => [['id' => 'v2', 'type' => 'text', 'data' => ['html' => '<p>Version 2</p>']]]]);
        $revision = $page->revisions()->firstOrFail();

        $this->actingAs($this->admin)->post(route('pages.revisions.restore', [$page, $revision]))
            ->assertRedirect(route('pages.edit', $page));

        $this->assertSame(self::validBlocks(), $page->fresh()->blockList());
        // The version that was replaced (v2) was saved first.
        $this->assertSame('v2', $page->revisions()->first()->blocks['blocks'][0]['id']);
    }

    public function test_restoring_a_legacy_revision_makes_the_page_legacy_again(): void
    {
        $page = $this->page();
        $this->save($page, ['blocks' => self::validBlocks()]);
        $legacyRevision = $page->revisions()->firstOrFail();
        $this->assertNull($legacyRevision->blocks);

        $this->actingAs($this->admin)->post(route('pages.revisions.restore', [$page, $legacyRevision]));

        $this->assertFalse($page->fresh()->hasBlocks());
        $this->assertSame('<p>Legacy content</p>', $page->fresh()->content);
    }

    public function test_revision_excerpt_uses_the_text_blocks(): void
    {
        $revision = Revision::create([
            'model_type' => Page::class, 'model_id' => 1, 'title' => 'T', 'content' => '<p>Stale</p>',
            'blocks' => ['version' => 1, 'blocks' => self::validBlocks()],
        ]);
        $legacy = Revision::create(['model_type' => Page::class, 'model_id' => 1, 'title' => 'T', 'content' => '<p>Old <em>html</em></p>']);

        $this->assertSame('Hello world. Second', $revision->excerpt()); // text blocks only, not hero or columns
        $this->assertSame('Old html', $legacy->excerpt());
    }

    public function test_revision_limit_still_applies_to_block_changes(): void
    {
        $page = $this->blockPage();
        foreach (range(1, 22) as $i) {
            $page->update(['blocks' => ['version' => 1, 'blocks' => [['id' => "v{$i}", 'type' => 'text', 'data' => ['html' => "<p>{$i}</p>"]]]]]);
        }

        $this->assertSame(20, $page->revisions()->count());
    }

    // ----------------------------------------------------- rebuilds and scheduling

    public function test_changing_only_blocks_of_a_published_page_queues_a_rebuild(): void
    {
        $page = $this->blockPage();
        Queue::fake();

        $page->update(['blocks' => ['version' => 1, 'blocks' => [['id' => 'x', 'type' => 'text', 'data' => ['html' => '<p>x</p>']]]]]);

        Queue::assertPushed(RebuildAstroJob::class, 1);
    }

    public function test_changing_blocks_of_a_draft_queues_no_rebuild(): void
    {
        $page = $this->blockPage(attributes: ['status' => 'draft', 'published_at' => null]);
        Queue::fake();

        $page->update(['blocks' => ['version' => 1, 'blocks' => []]]);

        Queue::assertNothingPushed();
    }

    public function test_scheduled_block_pages_are_published_by_the_scheduler_with_their_blocks(): void
    {
        $page = $this->blockPage(attributes: ['status' => 'draft', 'published_at' => null, 'publish_at' => now()->subMinute()]);

        $this->artisan('content:publish-scheduled')->assertSuccessful();

        $this->assertSame('published', $page->fresh()->status);
        $this->assertSame(self::validBlocks(), $page->fresh()->blockList());
    }

    // ----------------------------------------------------------------- public API

    public function test_public_api_returns_blocks_and_empty_content_for_a_block_page(): void
    {
        $this->blockPage();

        $this->getJson('/api/public/pages/about')
            ->assertOk()
            ->assertJsonPath('content', '') // stale legacy content never reaches the site
            ->assertJsonPath('blocks', self::validBlocks());

        $this->getJson('/api/public/pages')
            ->assertJsonPath('0.blocks', self::validBlocks())
            ->assertJsonPath('0.excerpt', 'Welcome To the site Hello world. Second Left Right Get in touch We reply fast');
    }

    public function test_public_api_returns_null_blocks_and_content_for_a_legacy_page(): void
    {
        $this->page();

        $this->getJson('/api/public/pages/about')
            ->assertJsonPath('blocks', null)
            ->assertJsonPath('content', '<p>Legacy content</p>');
        $this->getJson('/api/public/pages')->assertJsonPath('0.excerpt', 'Legacy content');
    }

    public function test_block_page_excerpt_is_limited_to_160_characters(): void
    {
        $this->blockPage([['type' => 'text', 'id' => 't', 'data' => ['html' => '<p>'.str_repeat('word ', 100).'</p>']]]);

        $excerpt = $this->getJson('/api/public/pages')->json('0.excerpt');

        // At most 160 characters plus "..." (Str::limit drops a trailing space before it).
        $this->assertStringEndsWith('...', $excerpt);
        $this->assertLessThanOrEqual(163, mb_strlen($excerpt));
        $this->assertGreaterThan(150, mb_strlen($excerpt));
    }

    public function test_posts_api_is_unchanged_and_has_no_blocks(): void
    {
        Post::create(['author_id' => $this->admin->id, 'title' => 'P', 'slug' => 'p', 'content' => '<p>Post</p>', 'status' => 'published', 'published_at' => now()->subMinute()]);

        $this->getJson('/api/public/posts/p')->assertJsonMissingPath('blocks')->assertJsonPath('content', '<p>Post</p>');
        $this->getJson('/api/public/posts')->assertJsonMissingPath('0.blocks')->assertJsonPath('0.excerpt', 'Post');
    }

    public function test_og_image_order_for_block_pages(): void
    {
        $og = fn () => $this->getJson('/api/public/pages/about')->json('og_image');
        $page = $this->blockPage();

        // 1. the editor-set og_image
        $page->update(['og_image' => 'https://admin.example.com/storage/9/conversions/x-og.jpg']);
        $this->assertSame('https://admin.example.com/storage/9/conversions/x-og.jpg', $og());

        // 2. the hero image (relative paths are made absolute)
        $page->update(['og_image' => null]);
        $this->assertSame('https://admin.example.com/storage/1/hero.jpg', $og());

        // 3. the first image block
        $blocks = self::validBlocks();
        $blocks[0]['data']['image'] = null;
        $page->update(['blocks' => ['version' => 1, 'blocks' => $blocks]]);
        $this->assertSame('https://cdn.example.com/photo.jpg', $og());

        // 4. the first column image
        unset($blocks[2]);
        $page->update(['blocks' => ['version' => 1, 'blocks' => array_values($blocks)]]);
        $this->assertSame('https://admin.example.com/storage/2/col.jpg', $og());

        // 5. then the existing fallbacks: an image in the text blocks (not the stale content)
        $page->update(['content' => '<img src="https://stale.example.com/old.jpg">', 'blocks' => ['version' => 1, 'blocks' => [
            ['id' => 't', 'type' => 'text', 'data' => ['html' => '<p><img src="/storage/5/inline.jpg" alt=""></p>']],
        ]]]);
        $this->assertSame('https://admin.example.com/storage/5/inline.jpg', $og());

        // nothing left
        $page->update(['blocks' => ['version' => 1, 'blocks' => []]]);
        $this->assertNull($og());
    }

    public function test_og_image_order_for_legacy_pages_is_unchanged(): void
    {
        $this->page(['content' => '<p><img src="/storage/3/legacy.jpg"></p>']);

        $this->getJson('/api/public/pages/about')->assertJsonPath('og_image', 'https://admin.example.com/storage/3/legacy.jpg');
    }

    // ----------------------------------------------------------------- importer

    public function test_the_wordpress_importer_still_creates_legacy_pages(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel><item>
    <title><![CDATA[Imported page]]></title>
    <content:encoded><![CDATA[<p>From WordPress</p>]]></content:encoded>
    <wp:post_id>5</wp:post_id>
    <wp:status><![CDATA[publish]]></wp:status>
    <wp:post_type><![CDATA[page]]></wp:post_type>
</item></channel></rss>
XML);

        $results = app(WordPressImporter::class)->import($path, $this->admin);
        @unlink($path);

        $this->assertSame(1, $results['imported_pages']);
        $page = Page::where('slug', 'imported-page')->firstOrFail();
        $this->assertFalse($page->hasBlocks());
        $this->assertSame('<p>From WordPress</p>', $page->content);
    }

    /**
     * In PHP, $ also matches before a trailing newline. Over HTTP the TrimStrings
     * middleware removes it first, so the rule itself is tested here.
     */
    public function test_a_block_id_with_a_trailing_newline_is_rejected(): void
    {
        $registry = app(BlockRegistry::class);
        $validate = fn (string $id) => Validator::make(
            ['blocks' => [['id' => $id, 'type' => 'text', 'data' => ['html' => '<p>x</p>']]]],
            $registry->rules([['id' => $id, 'type' => 'text', 'data' => ['html' => '<p>x</p>']]]),
        );

        $this->assertTrue($validate('abc-123_X')->passes());
        $this->assertTrue($validate("abc\n")->fails());
        $this->assertArrayHasKey('blocks.0.id', $validate("abc\n")->errors()->toArray());
    }
}
