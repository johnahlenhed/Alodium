<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function scheduledPost(Carbon $publishAt, array $attributes = []): Post
    {
        return Post::create([
            'author_id' => $this->user->id,
            'title' => 'Scheduled',
            'slug' => 'scheduled-'.uniqid(),
            'status' => 'draft',
            'publish_at' => $publishAt,
            ...$attributes,
        ]);
    }

    public function test_scheduled_content_is_not_returned_by_the_public_api(): void
    {
        $post = $this->scheduledPost(now()->addHour(), ['slug' => 'later']);
        Page::create(['author_id' => $this->user->id, 'title' => 'Later', 'slug' => 'later-page', 'status' => 'draft', 'publish_at' => now()->addHour()]);

        $this->getJson('/api/public/posts')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/public/posts/later')->assertNotFound();
        $this->getJson('/api/public/pages')->assertOk()->assertJsonCount(0);

        $post->update(['status' => 'published', 'published_at' => now(), 'publish_at' => null]);
        $this->getJson('/api/public/posts/later')->assertOk();
    }

    public function test_command_publishes_content_whose_time_has_passed(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
        $post = $this->scheduledPost(now()->subMinute());
        $page = Page::create(['author_id' => $this->user->id, 'title' => 'P', 'slug' => 'p', 'status' => 'draft', 'publish_at' => now()]);

        $this->artisan('content:publish-scheduled')
            ->expectsOutput('Published 2 scheduled item(s).')
            ->assertSuccessful();

        foreach ([$post->fresh(), $page->fresh()] as $item) {
            $this->assertSame('published', $item->status);
            $this->assertNull($item->publish_at);
            $this->assertTrue($item->published_at->equalTo(now()));
        }
    }

    public function test_command_keeps_an_existing_published_at(): void
    {
        $original = now()->subYear()->startOfSecond();
        $post = $this->scheduledPost(now()->subMinute(), ['published_at' => $original]);

        $this->artisan('content:publish-scheduled')->assertSuccessful();

        $this->assertTrue($post->fresh()->published_at->equalTo($original));
    }

    public function test_command_does_not_publish_future_or_unscheduled_content(): void
    {
        $future = $this->scheduledPost(now()->addMinute());
        $plainDraft = $this->scheduledPost(now()->subDay(), ['publish_at' => null]);

        $this->artisan('content:publish-scheduled')
            ->expectsOutput('Published 0 scheduled item(s).')
            ->assertSuccessful();

        $this->assertSame('draft', $future->fresh()->status);
        $this->assertNotNull($future->fresh()->publish_at);
        $this->assertSame('draft', $plainDraft->fresh()->status);
    }

    public function test_publishing_via_the_command_queues_an_astro_rebuild(): void
    {
        $this->scheduledPost(now()->subMinute());
        Queue::fake();

        $this->artisan('content:publish-scheduled')->assertSuccessful();

        Queue::assertPushed(RebuildAstroJob::class, 1);
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('content:publish-scheduled')
            ->assertSuccessful();
    }

    public function test_setting_status_to_scheduled_saves_draft_with_publish_at(): void
    {
        // As sent by the form: local time converted to UTC in the browser.
        $publishAt = now()->addDay()->startOfMinute()->utc();

        $this->actingAs($this->user)->post('/posts', [
            'title' => 'Future post',
            'status' => 'scheduled',
            'publish_at' => $publishAt->toIso8601ZuluString(),
        ])->assertRedirect(route('posts.index'));

        $post = Post::firstOrFail();
        $this->assertSame('draft', $post->status);
        $this->assertTrue($post->publish_at->equalTo($publishAt));
        $this->assertNull($post->published_at);
    }

    public function test_publish_at_with_a_timezone_offset_is_stored_in_utc(): void
    {
        $this->actingAs($this->user)->post('/pages', [
            'title' => 'Stockholm time',
            'status' => 'scheduled',
            'publish_at' => now()->addYear()->setTime(14, 30)->format('Y-m-d\TH:i:s').'+02:00',
        ]);

        $this->assertSame('12:30', Page::firstOrFail()->publish_at->utc()->format('H:i'));
    }

    public function test_scheduled_status_requires_a_future_publish_at(): void
    {
        $this->actingAs($this->user)
            ->post('/posts', ['title' => 'X', 'status' => 'scheduled'])
            ->assertSessionHasErrors('publish_at');

        $this->actingAs($this->user)
            ->post('/posts', ['title' => 'X', 'status' => 'scheduled', 'publish_at' => now()->subHour()->toIso8601String()])
            ->assertSessionHasErrors('publish_at');

        $this->assertSame(0, Post::count());
    }

    public function test_setting_status_to_published_clears_publish_at(): void
    {
        $post = $this->scheduledPost(now()->addDay());

        $this->actingAs($this->user)->put("/posts/{$post->id}", [
            'title' => 'Published now',
            'status' => 'published',
            // A stale value from the form must be ignored, even if in the past.
            'publish_at' => now()->subDay()->toIso8601String(),
        ])->assertSessionHasNoErrors();

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertNull($post->publish_at);
        $this->assertNotNull($post->published_at);
    }

    public function test_setting_status_to_draft_clears_publish_at(): void
    {
        $page = Page::create(['author_id' => $this->user->id, 'title' => 'P', 'slug' => 'p', 'status' => 'draft', 'publish_at' => now()->addDay()]);

        $this->actingAs($this->user)->put("/pages/{$page->id}", ['title' => 'P', 'status' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertNull($page->fresh()->publish_at);
        $this->assertSame('draft', $page->fresh()->status);
    }
}
