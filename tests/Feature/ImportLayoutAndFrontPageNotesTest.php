<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\WordPressImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What an import makes visible without changing what it imports: a note per item
 * whose columns, cover section or buttons were flattened, and, on the run page,
 * a note when the site has no front page (with an imported "Home" page as a guess).
 */
class ImportLayoutAndFrontPageNotesTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><p>One</p></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><p>Two</p></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><p>Three</p></div><!-- /wp:column --></div><!-- /wp:columns -->';

    private const BUTTONS = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="/contact">Ask us</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

    private const COVER = '<!-- wp:cover {"url":"https://wp.example.com/hero.jpg"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" src="https://wp.example.com/hero.jpg" alt=""/><div class="wp-block-cover__inner-container"><h1>Welcome</h1></div></div><!-- /wp:cover -->';

    private User $admin;

    private int $id = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create();
    }

    private function item(string $title, string $content, string $type = 'page', string $status = 'publish', string $postName = ''): string
    {
        $id = $this->id++;

        return <<<XML
<item>
    <title><![CDATA[{$title}]]></title>
    <content:encoded><![CDATA[{$content}]]></content:encoded>
    <wp:post_id>{$id}</wp:post_id>
    <wp:post_name><![CDATA[{$postName}]]></wp:post_name>
    <wp:post_date_gmt><![CDATA[2024-05-01 08:00:00]]></wp:post_date_gmt>
    <wp:status><![CDATA[{$status}]]></wp:status>
    <wp:post_type><![CDATA[{$type}]]></wp:post_type>
</item>
XML;
    }

    private function import(string ...$items): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>T</title><wp:wxr_version>1.2</wp:wxr_version>'.implode('', $items).'</channel></rss>';
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, $xml);

        try {
            return app(WordPressImporter::class)->import($path, $this->admin);
        } finally {
            @unlink($path);
        }
    }

    /** The layout notes of an import, by title. */
    private function layoutNotes(array $results): array
    {
        return collect($results['warnings'])->filter(fn (array $w) => str_contains($w['message'], "original layout wasn't preserved"))
            ->mapWithKeys(fn (array $w) => [$w['title'] => $w['message']])->all();
    }

    // --- flattened layout ------------------------------------------------------------

    public function test_flattened_columns_cover_sections_and_buttons_are_noted_per_item(): void
    {
        $results = $this->import(
            $this->item('Services', self::COLUMNS.self::BUTTONS),
            $this->item('Landing', self::COVER),
            $this->item('Everything', self::COVER.self::COLUMNS.self::BUTTONS),
            $this->item('Launch news', self::BUTTONS, type: 'post'),
            // Pasted HTML without the block comments is recognised by its class names.
            $this->item('Pasted', '<div class="wp-block-columns is-layout-flex"><div class="wp-block-column">A</div><div class="wp-block-column">B</div></div>'),
            // Ordinary content, and other blocks, get no note.
            $this->item('About', '<!-- wp:paragraph --><p>Plain text.</p><!-- /wp:paragraph --><!-- wp:image --><figure class="wp-block-image"><img src="https://wp.example.com/a.jpg" alt=""/></figure><!-- /wp:image -->'),
            $this->item('Classic', "Just a classic editor paragraph.\n\nAnd another."),
        );

        $this->assertSame([
            'Services' => "Columns and buttons in this page were kept as ordinary text, images and links, one after another: its original layout wasn't preserved.",
            'Landing' => "A cover section in this page was kept as ordinary text, images and links, one after another: its original layout wasn't preserved.",
            'Everything' => "Columns, a cover section and buttons in this page were kept as ordinary text, images and links, one after another: its original layout wasn't preserved.",
            'Launch news' => "Buttons in this post were kept as ordinary text, images and links, one after another: its original layout wasn't preserved.",
            'Pasted' => "Columns in this page were kept as ordinary text, images and links, one after another: its original layout wasn't preserved.",
        ], $this->layoutNotes($results));

        // Visibility only: the content is flattened exactly as before, and every item was imported.
        $this->assertSame(6, $results['imported_pages']);
        $this->assertSame('<p>One</p><p>Two</p><p>Three</p><a href="/contact">Ask us</a>', Page::where('slug', 'services')->value('content'));
        $this->assertNull(Page::where('slug', 'services')->value('blocks'));

        // The note is in the run's stored report, so the run page lists it with the other warnings.
        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page
            ->where('run.warnings', fn ($warnings) => collect($warnings)->contains(fn ($w) => $w['title'] === 'Services' && $w['slug'] === 'services' && str_contains($w['message'], 'Columns and buttons'))));
    }

    // --- front page note -----------------------------------------------------------------

    public function test_the_run_page_notes_a_missing_front_page_and_offers_an_imported_home_page_as_a_guess(): void
    {
        $results = $this->import($this->item('Home', self::COVER, postName: 'home'), $this->item('About', '<p>x</p>'));
        $home = Page::where('slug', 'home')->sole();

        $this->assertSame(['id' => $home->id, 'title' => 'Home', 'slug' => 'home'], $results['front_page_suggestion']);
        $this->assertNull(SiteSetting::current()->front_page_id, 'The import itself never sets the front page.');
        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page
            ->where('frontPage', ['suggestion' => ['id' => $home->id, 'title' => 'Home', 'slug' => 'home']]));
    }

    public function test_without_a_home_page_the_note_has_no_guess(): void
    {
        // A WordPress slug of "home" counts too; a draft can't be offered (it can't be the front page).
        $results = $this->import($this->item('Welcome', '<p>x</p>', status: 'draft', postName: 'home'), $this->item('About', '<p>x</p>'));

        $this->assertNull($results['front_page_suggestion']);
        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page
            ->where('frontPage', ['suggestion' => null]));

        $byWordPressSlug = $this->import($this->item('Start here', '<p>x</p>', postName: 'home'));
        $this->assertSame('start-here', $byWordPressSlug['front_page_suggestion']['slug']);
    }

    public function test_there_is_no_note_when_the_site_already_has_a_front_page(): void
    {
        $own = Page::create(['author_id' => $this->admin->id, 'title' => 'Our home', 'slug' => 'our-home', 'content' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);
        $settings = SiteSetting::current();
        $settings->front_page_id = $own->id;
        $settings->save();

        $results = $this->import($this->item('Home', '<p>x</p>'));

        $this->assertSame($own->id, SiteSetting::current()->front_page_id, 'Left as it was.');
        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page->where('frontPage', null));
    }

    public function test_there_is_no_note_after_an_import_without_pages(): void
    {
        $results = $this->import($this->item('Hello', '<p>x</p>', type: 'post'));

        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page->where('frontPage', null));
    }

    // --- one click: make it the home page ------------------------------------------------

    public function test_the_guess_can_be_made_the_front_page_in_one_click(): void
    {
        $results = $this->import($this->item('Home', '<p>x</p>'));
        $home = Page::where('slug', 'home')->sole();
        Queue::fake();

        $this->actingAs($this->admin)->from("/import/runs/{$results['run_id']}")->post("/pages/{$home->id}/front-page")
            ->assertRedirect("/import/runs/{$results['run_id']}")
            ->assertSessionHas('success', '"Home" is now the site\'s home page. The public site shows it in a minute or two; change it under Settings, Front page.');

        $this->assertSame($home->id, SiteSetting::current()->front_page_id);
        $this->getJson('/api/public/site')->assertJsonPath('front_page', ['slug' => 'home']);
        Queue::assertPushed(RebuildAstroJob::class);
        // Chosen: the note is gone.
        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page->where('frontPage', null));
    }

    public function test_only_admins_and_only_published_pages(): void
    {
        $draft = Page::create(['author_id' => $this->admin->id, 'title' => 'Draft', 'slug' => 'draft', 'content' => '<p>x</p>', 'status' => 'draft']);
        $published = Page::create(['author_id' => $this->admin->id, 'title' => 'Live', 'slug' => 'live', 'content' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($this->admin)->post("/pages/{$draft->id}/front-page")
            ->assertSessionHas('warning', 'Only a published page can be the home page. Publish it first, then choose it.');
        $this->actingAs(User::factory()->editor()->create())->post("/pages/{$published->id}/front-page")->assertForbidden();
        auth()->logout();
        $this->post("/pages/{$published->id}/front-page")->assertRedirect('/login');

        $this->assertNull(SiteSetting::current()->front_page_id);
    }
}
