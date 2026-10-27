<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Ada Admin']);
        $this->editor = User::factory()->editor()->create(['name' => 'Ed Editor']);
    }

    private function content(string $model, User $author, string $status = 'published'): Post|Page
    {
        return $model::create([
            'author_id' => $author->id,
            'title' => 'T',
            'slug' => 'slug-'.uniqid(),
            'content' => '<p>Body</p>',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    public function test_new_users_default_to_admin_and_helpers_work(): void
    {
        $user = User::create(['name' => 'Tinker', 'email' => 't@example.com', 'password' => 'secret-password']);

        $this->assertSame('admin', $user->fresh()->role); // database default
        $this->assertTrue($this->admin->isAdmin());
        $this->assertTrue($this->editor->isEditor());
        $this->assertFalse($this->editor->isAdmin());
    }

    public function test_editors_cannot_delete_posts_pages_or_media(): void
    {
        Storage::fake('public');
        $post = $this->content(Post::class, $this->editor);
        $page = $this->content(Page::class, $this->editor);
        $media = $this->editor->addMedia(UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'))->toMediaCollection('library');

        $this->actingAs($this->editor)->delete("/posts/{$post->id}")->assertForbidden();
        $this->actingAs($this->editor)->delete("/pages/{$page->id}")->assertForbidden();
        $this->actingAs($this->editor)->delete("/media/{$media->id}")->assertForbidden();

        $this->assertModelExists($post);
        $this->assertModelExists($page);
        $this->assertModelExists($media);
    }

    public function test_editors_cannot_access_analytics_import_or_users(): void
    {
        foreach (['/analytics', '/import', '/import/result', '/users'] as $url) {
            $this->actingAs($this->editor)->get($url)->assertForbidden();
        }
        $this->actingAs($this->editor)->post('/import', [])->assertForbidden();
        $this->actingAs($this->editor)->patch("/users/{$this->admin->id}", ['role' => 'editor'])->assertForbidden();
        $this->actingAs($this->editor)->delete("/users/{$this->admin->id}")->assertForbidden();

        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_editors_can_create_and_publish_and_edit_published_content_by_others(): void
    {
        $this->actingAs($this->editor)->post('/posts', ['title' => 'Mine', 'status' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame('published', Post::where('title', 'Mine')->value('status'));

        $othersPublished = $this->content(Page::class, $this->admin);
        $this->actingAs($this->editor)->get("/pages/{$othersPublished->id}/edit")->assertOk();
        $this->actingAs($this->editor)->put("/pages/{$othersPublished->id}", ['title' => 'Edited', 'status' => 'published'])
            ->assertRedirect(route('pages.index'));
        $this->assertSame('Edited', $othersPublished->fresh()->title);
    }

    public function test_editors_cannot_edit_drafts_by_other_authors(): void
    {
        $othersDraft = $this->content(Post::class, $this->admin, 'draft');
        $ownDraft = $this->content(Post::class, $this->editor, 'draft');

        $this->actingAs($this->editor)->get("/posts/{$othersDraft->id}/edit")->assertForbidden();
        $this->actingAs($this->editor)->put("/posts/{$othersDraft->id}", ['title' => 'Hijack', 'status' => 'draft'])->assertForbidden();
        $this->actingAs($this->editor)->getJson("/posts/{$othersDraft->id}/revisions")->assertForbidden();
        $this->assertSame('T', $othersDraft->fresh()->title);

        $this->actingAs($this->editor)->get("/posts/{$ownDraft->id}/edit")->assertOk();
    }

    public function test_post_list_tells_the_ui_which_actions_are_allowed(): void
    {
        $this->content(Post::class, $this->admin, 'draft');

        $this->actingAs($this->editor)->get('/posts')->assertInertia(fn ($page) => $page
            ->where('posts.0.can.update', false)
            ->where('posts.0.can.delete', false));

        $this->actingAs($this->admin)->get('/posts')->assertInertia(fn ($page) => $page
            ->where('posts.0.can.update', true)
            ->where('posts.0.can.delete', true));
    }

    // From the security review: the list sent every item's full data, so an editor
    // could read another author's draft in the page data although the edit page refused it.
    public function test_editors_never_receive_the_content_of_other_authors_drafts_or_scheduled_items(): void
    {
        foreach ([Post::class => '/posts', Page::class => '/pages'] as $model => $url) {
            $draft = $this->content($model, $this->admin, 'draft');
            $draft->update(['content' => '<p>SECRET-DRAFT-BODY</p>', 'meta_description' => 'SECRET-DRAFT-META']);
            $scheduled = $this->content($model, $this->admin, 'draft');
            $scheduled->update(['content' => '<p>SECRET-SCHEDULED-BODY</p>', 'publish_at' => now()->addDay()]);

            $response = $this->actingAs($this->editor)->get($url)->assertOk();
            foreach (['SECRET-DRAFT-BODY', 'SECRET-DRAFT-META', 'SECRET-SCHEDULED-BODY'] as $secret) {
                $response->assertDontSee($secret, false);
            }

            // Still listed (title, status, dates, author) so the list works as before, just without an Edit button.
            $rows = collect($response->inertiaProps(ltrim($url, '/')))->keyBy('id');
            $this->assertSame(['id', 'title', 'status', 'publish_at', 'published_at', 'author', 'can'], array_keys($rows[$draft->id]));
            $this->assertSame(['id' => $this->admin->id, 'name' => 'Ada Admin'], $rows[$draft->id]['author']);
            $this->assertFalse($rows[$draft->id]['can']['update']);
            $this->assertNotNull($rows[$scheduled->id]['publish_at']); // shown as "scheduled"

            // The admin, who may edit them, still gets everything.
            $this->actingAs($this->admin)->get($url)
                ->assertSee('SECRET-DRAFT-BODY', false)
                ->assertSee('SECRET-SCHEDULED-BODY', false);
        }
    }

    public function test_editors_still_receive_full_data_for_their_own_drafts_and_published_items(): void
    {
        $own = $this->content(Post::class, $this->editor, 'draft');
        $own->update(['content' => '<p>MY-OWN-DRAFT</p>']);
        $published = $this->content(Post::class, $this->admin);
        $published->update(['content' => '<p>PUBLISHED-BY-ADMIN</p>']);

        $this->actingAs($this->editor)->get('/posts')
            ->assertSee('MY-OWN-DRAFT', false)
            ->assertSee('PUBLISHED-BY-ADMIN', false);
    }

    public function test_admins_can_access_everything(): void
    {
        foreach (['/posts', '/pages', '/media', '/analytics', '/import', '/users'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        $post = $this->content(Post::class, $this->editor, 'draft');
        $this->actingAs($this->admin)->get("/posts/{$post->id}/edit")->assertOk();
        $this->actingAs($this->admin)->delete("/posts/{$post->id}")->assertRedirect(route('posts.index'));
        $this->assertModelMissing($post);
    }

    public function test_users_page_lists_users_with_roles(): void
    {
        $this->actingAs($this->admin)->get('/users')->assertInertia(fn ($page) => $page
            ->component('Users/Index')
            ->has('users', 2)
            ->where('users.0.name', 'Ada Admin')
            ->where('users.0.role', 'admin')
            ->where('users.1.role', 'editor')
            ->missing('users.0.password'));
    }

    public function test_role_change_works(): void
    {
        $this->actingAs($this->admin)->patch("/users/{$this->editor->id}", ['role' => 'admin'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($this->editor->fresh()->isAdmin());

        $this->actingAs($this->admin)->patch("/users/{$this->editor->id}", ['role' => 'owner'])
            ->assertSessionHasErrors('role');
    }

    public function test_admins_cannot_change_their_own_role(): void
    {
        $this->actingAs($this->admin)->patch("/users/{$this->admin->id}", ['role' => 'editor'])->assertForbidden();

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_cannot_delete_own_account(): void
    {
        $this->actingAs($this->admin)->delete("/users/{$this->admin->id}")->assertForbidden();

        $this->assertModelExists($this->admin);
    }

    public function test_deleting_a_user_transfers_their_content_and_media_instead_of_deleting_it(): void
    {
        Storage::fake('public');
        $post = $this->content(Post::class, $this->editor);
        $page = $this->content(Page::class, $this->editor, 'draft');
        $media = $this->editor->addMedia(UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'))->toMediaCollection('library');

        $this->actingAs($this->admin)->delete("/users/{$this->editor->id}")->assertRedirect();

        $this->assertModelMissing($this->editor);
        $this->assertSame($this->admin->id, $post->fresh()->author_id);
        $this->assertSame($this->admin->id, $page->fresh()->author_id);
        $this->assertSame($this->admin->id, (int) Media::findOrFail($media->id)->model_id);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_self_registration_creates_editors(): void
    {
        config(['auth.registration' => true]);

        $this->post('/register', [
            'name' => 'New', 'email' => 'new@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ]);

        $this->assertSame('editor', User::where('email', 'new@example.com')->value('role'));
    }
}
