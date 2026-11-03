<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** A page's own background colour (pages.background_color): the page form and the public API. */
class PageBackgroundColorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create();
    }

    private function create(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/pages', ['title' => 'About', 'status' => 'published', 'blocks' => [], ...$data]);
    }

    private function update(Page $page, array $data): TestResponse
    {
        return $this->actingAs($this->admin)->put("/pages/{$page->id}", ['title' => $page->title, 'status' => 'published', ...$data]);
    }

    private function publicBackground(string $slug = 'about'): mixed
    {
        return $this->getJson("/api/public/pages/{$slug}")->assertOk()->json('background_color');
    }

    public function test_a_page_has_no_background_colour_unless_one_is_chosen(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $page = Page::sole();

        $this->assertNull($page->background_color);
        $this->getJson('/api/public/pages/about')->assertJsonPath('background_color', null);
        // The form gets the brand colour for its readout, and the page's (empty) colour.
        $this->actingAs($this->admin)->get("/pages/{$page->id}/edit")->assertInertia(fn (Assert $p) => $p
            ->where('page.background_color', null)
            ->where('brandColor', '#1B2A4A'));
        $this->actingAs($this->admin)->get('/pages/create')->assertInertia(fn (Assert $p) => $p->where('brandColor', '#1B2A4A'));
    }

    public function test_a_page_from_before_the_column_is_unchanged(): void
    {
        $page = Page::create(['author_id' => $this->admin->id, 'title' => 'Old', 'slug' => 'old', 'content' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);

        $this->assertNull($page->fresh()->background_color);
        $this->assertNull($this->publicBackground('old'));
        // A save that doesn't send the field (an editor page loaded before it existed) keeps it as it is.
        $this->update($page, ['content' => '<p>y</p>'])->assertSessionHasNoErrors();
        $this->assertNull($page->fresh()->background_color);
    }

    public function test_the_colour_can_be_set_changed_and_cleared(): void
    {
        $this->create(['background_color' => '#faf7f2'])->assertSessionHasNoErrors();
        $page = Page::sole();
        $this->assertSame('#FAF7F2', $page->background_color, 'Stored uppercase, like the brand colour.');
        $this->assertSame('#FAF7F2', $this->publicBackground());

        $this->update($page, ['background_color' => '#F0F4FF'])->assertSessionHasNoErrors();
        $this->assertSame('#F0F4FF', $this->publicBackground());

        // Kept when the field isn't sent, cleared with null.
        $this->update($page, ['meta_title' => 'About us'])->assertSessionHasNoErrors();
        $this->assertSame('#F0F4FF', $page->fresh()->background_color);
        $this->update($page, ['background_color' => null])->assertSessionHasNoErrors();
        $this->assertNull($page->fresh()->background_color);
        $this->assertNull($this->publicBackground());
    }

    public function test_a_colour_the_page_would_be_hard_to_read_on_is_rejected(): void
    {
        $this->create(['background_color' => '#FAF7F2']);
        $page = Page::sole();

        foreach (['#1B2A4A', '#777777', '#E0E0E0', '#000000'] as $dark) {
            $this->update($page, ['background_color' => $dark])->assertSessionHasErrors('background_color');
            $this->assertStringStartsWith('This colour is too dark for the page: ', session('errors')->first('background_color'), $dark);
        }
        // The check furthest below its minimum is named; the green success message is usually the first to fail.
        $this->update($page, ['background_color' => '#E0E0E0'])->assertSessionHasErrors([
            'background_color' => "This colour is too dark for the page: the contact form's success message would be hard to read on it (contrast 4.0:1, minimum 4.5:1). Try a lighter shade.",
        ]);
        // Not a colour at all: the same message format as the brand colour's.
        foreach (['white', '#fff', 'FAF7F2', 'transparent', 'red; background:url(//evil)', 42] as $invalid) {
            $this->update($page, ['background_color' => $invalid])->assertSessionHasErrors([
                'background_color' => 'Enter the colour as # followed by 6 hexadecimal digits, for example #F5F1EA.',
            ]);
        }

        $this->assertSame('#FAF7F2', $page->fresh()->background_color, 'Nothing was saved.');
    }

    public function test_the_api_never_sends_a_colour_the_rule_refuses(): void
    {
        $this->create(['background_color' => '#FAF7F2']);
        $page = Page::sole();

        // Written past validation (directly in the database, say): the page is shown
        // without it rather than with unreadable text, and nothing unchecked reaches CSS.
        foreach (['#333333', 'red;}body{display:none', '#fff'] as $stored) {
            $page->forceFill(['background_color' => $stored])->saveQuietly();
            $this->assertNull($this->publicBackground(), $stored);
        }

        // The rule is checked with the brand colour the site uses (an invalid saved one means the default).
        $settings = SiteSetting::current();
        $settings->brand_color = '#FFFF00';
        $settings->saveQuietly();
        $page->forceFill(['background_color' => '#FAF7F2'])->saveQuietly();
        $this->assertSame('#FAF7F2', $this->publicBackground());
        $this->actingAs($this->admin)->get("/pages/{$page->id}/edit")->assertInertia(fn (Assert $p) => $p->where('brandColor', '#1B2A4A'));
    }

    public function test_editors_can_set_it_on_their_pages_and_posts_have_none(): void
    {
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor)->post('/pages', ['title' => 'Mine', 'status' => 'published', 'blocks' => [], 'background_color' => '#FFF3C4'])
            ->assertSessionHasNoErrors();
        $this->assertSame('#FFF3C4', $this->publicBackground('mine'));

        Post::create(['author_id' => $this->admin->id, 'title' => 'News', 'slug' => 'news', 'content' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);
        $this->assertArrayNotHasKey('background_color', $this->getJson('/api/public/posts/news')->assertOk()->json());
    }
}
