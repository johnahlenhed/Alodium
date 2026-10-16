<?php

namespace Tests\Feature;

use App\Mail\ContactSubmissionReceived;
use App\Models\Page;
use App\Models\Post;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicContentTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $model, array $attributes): Post|Page
    {
        return $model::create([
            'author_id' => User::factory()->create(['name' => 'Ada'])->id,
            'title' => 'Title',
            'slug' => 'slug',
            'status' => 'published',
            'published_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    public function test_listing_shows_only_published_posts_newest_first(): void
    {
        $this->make(Post::class, ['slug' => 'old', 'published_at' => now()->subDays(5)]);
        $this->make(Post::class, ['slug' => 'new', 'published_at' => now()->subDay(), 'content' => '<p>Hello <strong>world</strong>.</p><p>Second&nbsp;para.</p>']);
        $this->make(Post::class, ['slug' => 'draft', 'status' => 'draft', 'published_at' => null]);
        $this->make(Post::class, ['slug' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->getJson('/api/public/posts')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.slug', 'new')
            ->assertJsonPath('0.author', 'Ada')
            ->assertJsonPath('0.excerpt', 'Hello world. Second para.')
            ->assertJsonPath('1.slug', 'old')
            ->assertJsonMissingPath('0.content');
    }

    public function test_excerpt_is_limited_to_160_characters(): void
    {
        $this->make(Post::class, ['content' => '<p>'.str_repeat('a', 300).'</p>']);

        $excerpt = $this->getJson('/api/public/posts')->json('0.excerpt');

        $this->assertSame(160, mb_strlen(rtrim($excerpt, '.')));
    }

    public function test_single_post_and_page_and_404s(): void
    {
        $this->make(Post::class, ['slug' => 'hello', 'content' => '<p>Body</p>', 'meta_title' => 'SEO title']);
        $this->make(Page::class, ['slug' => 'about', 'content' => '<p>About</p>']);
        $this->make(Post::class, ['slug' => 'secret', 'status' => 'draft']);

        $this->getJson('/api/public/posts/hello')
            ->assertOk()
            ->assertJsonPath('content', '<p>Body</p>')
            ->assertJsonPath('meta_title', 'SEO title')
            ->assertJsonPath('author', 'Ada')
            ->assertJsonStructure(['id', 'title', 'slug', 'content', 'meta_title', 'meta_description', 'og_image', 'published_at', 'author']);

        $this->getJson('/api/public/pages/about')->assertOk()->assertJsonPath('content', '<p>About</p>');
        $this->getJson('/api/public/pages')->assertOk()->assertJsonCount(1);

        $this->getJson('/api/public/posts/secret')->assertNotFound()->assertJson(['message' => 'Not found.']);
        $this->getJson('/api/public/posts/missing')->assertNotFound();
        $this->getJson('/api/public/pages/hello')->assertNotFound(); // a post slug is not a page
    }

    public function test_contact_stores_submission_and_emails_admin(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'admin@alodium.dev']);

        $this->postJson('/api/public/contact', [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'message' => "Hi!\nSecond line",
        ])->assertOk()->assertExactJson(['success' => true]);

        $this->assertDatabaseHas('submissions', ['name' => 'Grace', 'email' => 'grace@example.com', 'read' => false]);
        Mail::assertSent(ContactSubmissionReceived::class, fn ($mail) => $mail->hasTo('admin@alodium.dev')
            && $mail->hasReplyTo('grace@example.com'));
    }

    public function test_contact_validation_honeypot_and_throttle(): void
    {
        Mail::fake();

        $this->postJson('/api/public/contact', ['email' => 'not-an-email'])
            ->assertJsonValidationErrors(['name', 'email', 'message']);

        $this->postJson('/api/public/contact', [
            'name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'spam', 'website' => 'http://spam.example',
        ])->assertOk()->assertExactJson(['success' => true]);
        $this->assertSame(0, Submission::count());
        Mail::assertNothingSent();

        // 5 per minute per IP; 2 used above.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/public/contact', ['name' => 'A', 'email' => 'a@example.com', 'message' => 'm'])->assertOk();
        }
        $this->postJson('/api/public/contact', ['name' => 'A', 'email' => 'a@example.com', 'message' => 'm'])->assertStatus(429);
    }

    public function test_contact_still_succeeds_when_mail_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Resend is down'));

        $this->postJson('/api/public/contact', ['name' => 'A', 'email' => 'a@example.com', 'message' => 'm'])
            ->assertOk();
        $this->assertSame(1, Submission::count());
    }

    public function test_contact_mail_renders_and_escapes_message(): void
    {
        $submission = Submission::create(['name' => 'Eve', 'email' => 'eve@example.com', 'message' => '<script>x()</script>']);

        $html = (new ContactSubmissionReceived($submission))->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>x()', $html);
    }

    public function test_api_allows_cross_origin_requests_from_the_public_site(): void
    {
        $this->call('OPTIONS', '/api/public/contact', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:4321',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
