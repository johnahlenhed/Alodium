<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\Revision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevisionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makePost(string $status = 'published', array $attributes = []): Post
    {
        return Post::create([
            'author_id' => $this->user->id,
            'title' => 'Original title',
            'slug' => 'post-'.uniqid(),
            'content' => '<p>Version 1</p>',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            ...$attributes,
        ]);
    }

    public function test_updating_a_published_post_saves_the_previous_version(): void
    {
        $post = $this->makePost();

        $post->update(['content' => '<p>Version 2</p>']);

        $this->assertCount(1, $post->revisions);
        $revision = $post->revisions->first();
        $this->assertSame('<p>Version 1</p>', $revision->content); // the replaced version
        $this->assertSame('Original title', $revision->title);
        $this->assertSame('<p>Version 2</p>', $post->fresh()->content);
    }

    public function test_title_change_on_a_published_post_also_creates_a_revision(): void
    {
        $post = $this->makePost();

        $post->update(['title' => 'New title']);

        $this->assertSame('Original title', $post->revisions()->first()->title);
    }

    public function test_updating_a_draft_creates_no_revision(): void
    {
        $post = $this->makePost('draft');

        $post->update(['content' => '<p>Draft edit</p>']);
        $post->update(['title' => 'Draft title edit']);

        $this->assertSame(0, Revision::count());
    }

    public function test_first_publish_of_a_draft_creates_no_revision(): void
    {
        // The version being replaced is an unpublished draft, and drafts are not versioned.
        $post = $this->makePost('draft');

        $post->update(['status' => 'published', 'content' => '<p>Published text</p>']);

        $this->assertSame(0, Revision::count());
    }

    public function test_unchanged_content_or_metadata_only_changes_create_no_revision(): void
    {
        $post = $this->makePost();

        $post->update(['content' => '<p>Version 1</p>']); // identical
        $post->update(['meta_title' => 'SEO', 'meta_description' => 'Desc', 'slug' => 'new-slug']);
        $post->update(['status' => 'draft']);              // status only

        $this->assertSame(0, Revision::count());
    }

    public function test_snapshot_identical_to_the_newest_revision_is_skipped(): void
    {
        $post = $this->makePost();
        $post->saveRevision();

        $this->assertNull($post->saveRevision());
        $this->assertSame(1, $post->revisions()->count());
    }

    public function test_only_the_newest_20_revisions_are_kept(): void
    {
        $post = $this->makePost();

        foreach (range(2, 26) as $version) {
            $post->update(['content' => "<p>Version {$version}</p>"]);
        }

        $revisions = $post->revisions()->pluck('content');
        $this->assertCount(20, $revisions);
        $this->assertSame('<p>Version 25</p>', $revisions->first()); // newest replaced version
        $this->assertSame('<p>Version 6</p>', $revisions->last());   // versions 1-5 pruned
    }

    public function test_revisions_are_kept_per_post(): void
    {
        $a = $this->makePost();
        $b = $this->makePost();
        foreach (range(2, 23) as $version) {
            $a->update(['content' => "<p>A{$version}</p>"]);
        }
        $b->update(['content' => '<p>B2</p>']);

        $this->assertSame(20, $a->revisions()->count());
        $this->assertSame(1, $b->revisions()->count()); // not pruned by the other post
    }

    public function test_restoring_saves_current_content_first_and_restores_content_only(): void
    {
        $post = $this->makePost();
        $post->update(['content' => '<p>Version 2</p>']);
        $post->update(['title' => 'Current title', 'content' => '<p>Version 3</p>', 'meta_title' => 'Current SEO']);
        $versionOne = $post->revisions()->where('content', '<p>Version 1</p>')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('posts.revisions.restore', [$post, $versionOne]))
            ->assertRedirect(route('posts.edit', $post))
            ->assertSessionHas('success');

        $post->refresh();
        $this->assertSame('<p>Version 1</p>', $post->content);
        $this->assertSame('Current title', $post->title);       // title untouched
        $this->assertSame('Current SEO', $post->meta_title);    // meta untouched
        $this->assertSame('<p>Version 3</p>', $post->revisions()->first()->content); // saved first
        $this->assertSame(3, $post->revisions()->count());      // v1, v2 + the saved v3 (no duplicate)
    }

    public function test_restoring_works_for_drafts_and_pages(): void
    {
        $page = Page::create([
            'author_id' => $this->user->id, 'title' => 'About', 'slug' => 'about',
            'content' => '<p>Old</p>', 'status' => 'published', 'published_at' => now(),
        ]);
        $page->update(['content' => '<p>New</p>']);
        $page->update(['status' => 'draft']);
        $old = $page->revisions()->firstOrFail();

        $this->actingAs($this->user)->post(route('pages.revisions.restore', [$page, $old]))
            ->assertRedirect(route('pages.edit', $page));

        $this->assertSame('<p>Old</p>', $page->fresh()->content);
        $this->assertSame('<p>New</p>', $page->revisions()->first()->content);
    }

    public function test_a_revision_of_another_post_cannot_be_restored(): void
    {
        $mine = $this->makePost();
        $other = $this->makePost();
        $other->update(['content' => '<p>Other v2</p>']);

        $this->actingAs($this->user)
            ->post(route('posts.revisions.restore', [$mine, $other->revisions()->first()]))
            ->assertNotFound();

        $this->assertSame('<p>Version 1</p>', $mine->fresh()->content);
    }

    public function test_revisions_endpoint_returns_newest_first_with_excerpts(): void
    {
        $post = $this->makePost('published', ['content' => '<p>First <strong>version</strong>.</p><p>'.str_repeat('x', 200).'</p>']);
        $post->update(['content' => '<p>Second</p>']);
        $post->update(['content' => '<p>Third</p>']);

        $response = $this->actingAs($this->user)->getJson(route('posts.revisions', $post))->assertOk();

        $response->assertJsonCount(2)
            ->assertJsonPath('0.excerpt', 'Second')
            ->assertJsonStructure([['id', 'title', 'excerpt', 'created_at']]);
        $this->assertStringStartsWith('First version. xxx', $response->json('1.excerpt'));
        $this->assertSame(103, mb_strlen($response->json('1.excerpt'))); // 100 + "..."
    }

    public function test_revisions_endpoint_requires_login(): void
    {
        $this->getJson(route('posts.revisions', $this->makePost()))->assertUnauthorized();
    }

    public function test_edit_page_receives_revisions(): void
    {
        $post = $this->makePost();
        $post->update(['content' => '<p>Version 2</p>']);

        $this->actingAs($this->user)->get(route('posts.edit', $post))
            ->assertInertia(fn ($page) => $page
                ->component('Posts/Edit')
                ->has('revisions', 1)
                ->where('revisions.0.excerpt', 'Version 1'));
    }

    public function test_deleting_a_post_deletes_its_revisions(): void
    {
        $post = $this->makePost();
        $post->update(['content' => '<p>Version 2</p>']);

        $post->delete();

        $this->assertSame(0, Revision::count());
    }
}
