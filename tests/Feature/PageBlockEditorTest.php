<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PageBlockEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
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

    private function blocks(string $heading = 'Welcome'): array
    {
        return [['id' => 'hero-1', 'type' => 'hero', 'data' => ['heading' => $heading, 'subheading' => null, 'image' => null, 'button_label' => null, 'button_url' => null]]];
    }

    public function test_create_starts_a_block_page_with_an_empty_list(): void
    {
        $this->actingAs($this->admin)->get('/pages/create')
            ->assertInertia(fn ($page) => $page
                ->component('Pages/Create')
                ->where('hasBlocks', true)
                ->where('blocks', []));
    }

    public function test_edit_passes_blocks_for_a_block_page(): void
    {
        $page = $this->page(['blocks' => ['version' => 1, 'blocks' => $this->blocks()]]);

        $this->actingAs($this->admin)->get("/pages/{$page->id}/edit")
            ->assertInertia(fn ($inertia) => $inertia
                ->component('Pages/Edit')
                ->where('hasBlocks', true)
                ->where('blocks', $this->blocks()));
    }

    public function test_edit_passes_the_legacy_content_as_one_text_block_for_conversion(): void
    {
        $page = $this->page();

        $this->actingAs($this->admin)->get("/pages/{$page->id}/edit")
            ->assertInertia(fn ($inertia) => $inertia
                ->where('hasBlocks', false)
                ->has('blocks', 1)
                ->where('blocks.0.type', 'text')
                ->where('blocks.0.data.html', '<p>Legacy content</p>'));
    }

    public function test_block_mode_save_without_content_leaves_content_untouched(): void
    {
        // What the editor sends when converting a legacy page: blocks, no content key.
        $page = $this->page();

        $this->actingAs($this->admin)->put("/pages/{$page->id}", [
            'title' => 'About', 'status' => 'published', 'blocks' => $this->blocks(),
        ])->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertTrue($page->hasBlocks());
        $this->assertSame('<p>Legacy content</p>', $page->content);
    }

    public function test_classic_mode_save_without_blocks_leaves_blocks_untouched(): void
    {
        $page = $this->page(['blocks' => ['version' => 1, 'blocks' => $this->blocks()]]);

        $this->actingAs($this->admin)->put("/pages/{$page->id}", [
            'title' => 'About', 'status' => 'published', 'content' => '<p>Edited</p>',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->blocks(), $page->fresh()->blockList());
    }

    public function test_after_restoring_a_revision_the_edit_page_shows_the_restored_blocks(): void
    {
        $page = $this->page(['blocks' => ['version' => 1, 'blocks' => $this->blocks('Version 1')]]);
        $this->actingAs($this->admin)->put("/pages/{$page->id}", ['title' => 'About', 'status' => 'published', 'blocks' => $this->blocks('Version 2')]);
        $before = $page->fresh()->updated_at;
        $this->travel(5)->seconds();

        $this->actingAs($this->admin)
            ->post(route('pages.revisions.restore', [$page, $page->revisions()->firstOrFail()]))
            ->assertRedirect(route('pages.edit', $page));

        $this->actingAs($this->admin)->get(route('pages.edit', $page))
            ->assertInertia(fn ($inertia) => $inertia
                ->where('hasBlocks', true)
                ->where('blocks.0.data.heading', 'Version 1')
                // The form is keyed on updated_at, which changes, so it re-initialises.
                ->where('page.updated_at', fn ($updatedAt) => $updatedAt !== $before->toJSON()));
    }

    public function test_restoring_a_legacy_revision_opens_the_page_in_the_classic_editor(): void
    {
        $page = $this->page();
        $this->actingAs($this->admin)->put("/pages/{$page->id}", ['title' => 'About', 'status' => 'published', 'blocks' => $this->blocks()]);

        $this->actingAs($this->admin)->post(route('pages.revisions.restore', [$page, $page->revisions()->firstOrFail()]));

        $this->actingAs($this->admin)->get(route('pages.edit', $page))
            ->assertInertia(fn ($inertia) => $inertia->where('hasBlocks', false)->where('blocks.0.data.html', '<p>Legacy content</p>'));
    }

    public function test_media_list_includes_medium_url_only_when_the_conversion_exists(): void
    {
        Storage::fake('public');
        $this->admin->addMedia(UploadedFile::fake()->image('wide.jpg', 1600, 900))->toMediaCollection('library');
        $this->admin->addMedia(UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'))->toMediaCollection('library');

        $media = collect($this->actingAs($this->admin)->getJson('/api/media')->assertOk()->json())->keyBy('original_filename');

        $this->assertStringContainsString('/conversions/wide-medium.webp', $media['wide.jpg']['medium_url']);
        $this->assertNull($media['logo.svg']['medium_url']); // no conversion generated
    }
}
