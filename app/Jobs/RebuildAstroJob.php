<?php

namespace App\Jobs;

use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Rebuilds the static Astro site so published changes go live.
 *
 * Many triggers, few builds:
 * - ShouldBeUniqueUntilProcessing: while a rebuild is waiting in the queue, further
 *   dispatches are dropped. Publishing 10 posts (or a WordPress import) = 1 build.
 * - WithoutOverlapping: two builds never run at the same time (they would write to
 *   the same dist/ folder). A rebuild queued during a running build waits for it,
 *   then runs, so changes made mid-build are still picked up.
 */
class RebuildAstroJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Retry once on failure (2 attempts in total). */
    public int $maxExceptions = 2;

    /** Seconds before the build is killed. The worker's --timeout must be higher. */
    public int $timeout = 600;

    /** Seconds to wait before the retry. */
    public int $backoff = 30;

    /** Safety net: drop the uniqueness lock if the queued job is lost. */
    public int $uniqueFor = 3600;

    /**
     * Waiting for a running build "releases" the job back onto the queue, and every
     * release counts as an attempt. Retries are therefore limited by $maxExceptions
     * (real failures) and a deadline, instead of $tries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('astro-rebuild'))
                ->releaseAfter(30)
                // Lock outlives the build timeout, but frees itself if a worker crashes.
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        $script = config('services.astro.rebuild_script');

        // Misconfiguration is logged but not retried: a retry cannot fix it.
        if (! $script) {
            Log::info('Astro rebuild skipped: ASTRO_REBUILD_SCRIPT is not set.');

            return;
        }
        if (! is_file($script) || ! is_executable($script)) {
            Log::error('Astro rebuild skipped: script is missing or not executable.', ['script' => $script]);

            return;
        }

        // Runs as the queue worker's user (www-data). No sudo, no shell interpolation.
        $result = Process::timeout($this->timeout - 30)->run([$script]);

        // Build output is long; the end is where errors are.
        $output = Str::substr(trim($result->output()."\n".$result->errorOutput()), -4000);

        if ($result->successful()) {
            Log::info('Astro rebuild succeeded.', ['output' => $output]);

            return;
        }

        Log::error('Astro rebuild failed.', ['exit_code' => $result->exitCode(), 'output' => $output]);

        // Throwing makes the queue retry the job (see $maxExceptions).
        throw new RuntimeException("Astro rebuild failed with exit code {$result->exitCode()}.");
    }

    /** Called once all attempts are used up. The live site keeps the previous build. */
    public function failed(?Throwable $exception): void
    {
        Log::critical('Astro rebuild gave up after retrying. The public site was not updated.', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
