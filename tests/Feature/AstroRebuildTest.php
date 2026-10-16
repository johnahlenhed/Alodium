<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class AstroRebuildTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $attributes = []): Post
    {
        return Post::create([
            'author_id' => User::factory()->create()->id,
            'title' => 'T',
            'slug' => 's'.uniqid(),
            'status' => 'draft',
            ...$attributes,
        ]);
    }

    /**
     * Asserts the total number of rebuilds queued so far, then releases the
     * uniqueness lock, as if the waiting job had been picked up by a worker.
     * Without that, every further dispatch would (correctly) be dropped.
     */
    private function assertRebuildsQueued(int $count): void
    {
        $count === 0 ? Queue::assertNothingPushed() : Queue::assertPushed(RebuildAstroJob::class, $count);
        Cache::lock(UniqueLock::getKey(new RebuildAstroJob))->forceRelease();
    }

    public function test_webhook_rejects_missing_wrong_or_unconfigured_secret(): void
    {
        Queue::fake();

        config(['services.astro.webhook_secret' => null]);
        $this->postJson('/api/webhook/rebuild', [], ['X-Webhook-Secret' => ''])->assertUnauthorized();

        config(['services.astro.webhook_secret' => 'correct-secret']);
        $this->postJson('/api/webhook/rebuild')->assertUnauthorized();
        $this->postJson('/api/webhook/rebuild', [], ['X-Webhook-Secret' => 'wrong'])->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_webhook_with_valid_secret_queues_rebuild_and_returns_202(): void
    {
        Queue::fake();
        config(['services.astro.webhook_secret' => 'correct-secret']);

        $this->postJson('/api/webhook/rebuild', [], ['X-Webhook-Secret' => 'correct-secret'])
            ->assertStatus(202);

        Queue::assertPushed(RebuildAstroJob::class, 1);
    }

    public function test_rebuild_is_queued_only_for_changes_visible_on_the_public_site(): void
    {
        Queue::fake();

        $draft = $this->makePost(['status' => 'draft']);
        $draft->update(['title' => 'Draft edit']);
        $this->assertRebuildsQueued(0);

        $draft->update(['status' => 'published']);   // publish
        $this->assertRebuildsQueued(1);

        $draft->update(['title' => 'Typo fix']);     // edit while published
        $this->assertRebuildsQueued(2);

        $draft->update(['title' => 'Typo fix']);     // no actual change
        $this->assertRebuildsQueued(2);

        $draft->update(['status' => 'draft']);       // unpublish
        $this->assertRebuildsQueued(3);

        $draft->delete();                            // deleting a draft
        $this->assertRebuildsQueued(3);

        $published = $this->makePost(['status' => 'published']); // create as published
        $this->assertRebuildsQueued(4);

        $published->delete();                        // delete a published post
        $this->assertRebuildsQueued(5);
    }

    public function test_publishing_via_the_admin_and_pages_also_trigger(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/posts', ['title' => 'Draft', 'status' => 'draft']);
        $this->assertRebuildsQueued(0);

        $this->actingAs($user)->post('/pages', ['title' => 'About', 'status' => 'published']);
        $this->assertRebuildsQueued(1);

        $page = Page::firstOrFail();
        $this->actingAs($user)->delete("/pages/{$page->id}");
        $this->assertRebuildsQueued(2);
    }

    public function test_only_one_rebuild_waits_in_the_queue_at_a_time(): void
    {
        // Uniqueness is enforced when pushing to a real queue, so use the database queue.
        config(['queue.default' => 'database']);

        RebuildAstroJob::dispatch();
        RebuildAstroJob::dispatch();
        RebuildAstroJob::dispatch();

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_job_runs_script_and_logs_success(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'rebuild');
        chmod($script, 0755);
        config(['services.astro.rebuild_script' => $script]);
        Process::fake(['*' => Process::result('Build complete')]);
        Log::spy();

        (new RebuildAstroJob)->handle();

        Process::assertRan(fn (PendingProcess $process) => $process->command === [$script] && $process->timeout === 570);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Astro rebuild succeeded.'
            && str_contains($context['output'], 'Build complete'));
        @unlink($script);
    }

    public function test_job_logs_and_throws_on_failure_so_the_queue_retries(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'rebuild');
        chmod($script, 0755);
        config(['services.astro.rebuild_script' => $script]);
        Process::fake(['*' => Process::result('', 'npm ERR! API unreachable', 1)]);
        Log::spy();

        try {
            (new RebuildAstroJob)->handle();
            $this->fail('Expected the job to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exit code 1', $e->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => $message === 'Astro rebuild failed.'
            && $context['exit_code'] === 1 && str_contains($context['output'], 'API unreachable'));
        $this->assertSame(2, (new RebuildAstroJob)->maxExceptions);
        @unlink($script);
    }

    public function test_job_skips_without_retrying_when_not_configured(): void
    {
        Process::fake();

        config(['services.astro.rebuild_script' => null]);
        (new RebuildAstroJob)->handle();

        config(['services.astro.rebuild_script' => '/nonexistent/rebuild-astro.sh']);
        (new RebuildAstroJob)->handle();

        Process::assertNothingRan();
    }
}
