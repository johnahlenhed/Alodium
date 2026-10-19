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

class OgImageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('public');
    }

    private function published(string $model, array $attributes = []): Post|Page
    {
        return $model::create([
            'author_id' => $this->user->id,
            'title' => 'T',
            'slug' => 'hello',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            ...$attributes,
        ]);
    }

    public function test_og_image_is_saved_from_the_form_and_returned_by_the_public_api(): void
    {
        $url = 'http://localhost:8000/storage/1/conversions/photo-og.jpg';

        $this->actingAs($this->user)->post('/posts', [
            'title' => 'Hello', 'status' => 'published', 'og_image' => $url,
        ])->assertSessionHasNoErrors();

        $this->assertSame($url, Post::firstOrFail()->og_image);
        $this->getJson('/api/public/posts/hello')
            ->assertOk()
            ->assertJsonPath('og_image', $url)
            ->assertJsonPath('og_image_width', 1200)
            ->assertJsonPath('og_image_height', 630);
    }

    public function test_og_image_on_pages_can_be_updated_and_removed(): void
    {
        $page = $this->published(Page::class, ['slug' => 'about', 'og_image' => 'https://example.com/old.jpg']);

        $this->actingAs($this->user)->put("/pages/{$page->id}", [
            'title' => 'About', 'status' => 'published', 'og_image' => 'https://example.com/new.jpg',
        ])->assertSessionHasNoErrors();
        $this->getJson('/api/public/pages/about')->assertJsonPath('og_image', 'https://example.com/new.jpg');

        $this->actingAs($this->user)->put("/pages/{$page->id}", [
            'title' => 'About', 'status' => 'published', 'og_image' => '',
        ]);
        $this->assertNull($page->fresh()->og_image);
    }

    public function test_og_image_must_be_an_http_url(): void
    {
        foreach (['not a url', 'javascript:alert(1)'] as $bad) {
            $this->actingAs($this->user)
                ->post('/posts', ['title' => 'X', 'status' => 'draft', 'og_image' => $bad])
                ->assertSessionHasErrors('og_image');
        }
    }

    public function test_falls_back_to_the_first_image_in_the_content(): void
    {
        $this->published(Post::class, ['slug' => 'abs', 'content' => '<p>Hi</p><img src="https://cdn.example/a.jpg"><img src="https://cdn.example/b.jpg">']);
        $this->published(Post::class, ['slug' => 'rel', 'content' => '<img src="/storage/x.png" alt="">']);
        $this->published(Post::class, ['slug' => 'none', 'content' => '<p>No images</p>']);
        config(['app.url' => 'https://alodium.dev']);

        $this->getJson('/api/public/posts/abs')
            ->assertJsonPath('og_image', 'https://cdn.example/a.jpg')
            ->assertJsonPath('og_image_width', null);
        $this->getJson('/api/public/posts/rel')->assertJsonPath('og_image', 'https://alodium.dev/storage/x.png');
        $this->getJson('/api/public/posts/none')->assertJsonPath('og_image', null);
    }

    public function test_featured_image_is_preferred_over_content_images(): void
    {
        $post = $this->published(Post::class, ['content' => '<img src="https://cdn.example/a.jpg">']);
        $post->addMedia(UploadedFile::fake()->image('featured.jpg', 1600, 900))->toMediaCollection('featured');

        $ogImage = $this->getJson('/api/public/posts/hello')->json('og_image');

        $this->assertStringEndsWith('featured-og.jpg', $ogImage);
    }

    public function test_media_list_endpoint_returns_media_for_admins_only(): void
    {
        $this->getJson('/api/media')->assertUnauthorized();

        $this->user->addMedia(UploadedFile::fake()->image('first.png', 800, 800))->toMediaCollection('library');
        $this->travel(1)->minute();
        $this->user->addMedia(UploadedFile::fake()->image('second.jpg', 2000, 1000))->toMediaCollection('library');

        $this->actingAs($this->user)->getJson('/api/media')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.original_filename', 'second.jpg') // newest first
            ->assertJsonStructure([['id', 'original_filename', 'url', 'thumb_url', 'og_url']]);
    }

    public function test_og_conversion_is_a_1200x630_jpeg(): void
    {
        $media = $this->user->addMedia(UploadedFile::fake()->image('wide.png', 2000, 1500))->toMediaCollection('library');

        $path = Media::findOrFail($media->id)->getPath('og');

        $this->assertSame([1200, 630], array_slice(getimagesize($path), 0, 2));
        $this->assertSame('image/jpeg', getimagesize($path)['mime']);
    }
}
