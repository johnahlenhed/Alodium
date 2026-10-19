<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Publishes posts and pages whose scheduled time (publish_at) has passed.
 * Runs every minute via the scheduler (routes/console.php).
 *
 * Each item is saved as a model (not a bulk UPDATE query), so model events
 * fire and PublicContentObserver queues an Astro rebuild. Several items
 * published in the same run still lead to a single rebuild.
 */
class PublishScheduledContent extends Command
{
    protected $signature = 'content:publish-scheduled';

    protected $description = 'Publish posts and pages whose scheduled publish time has passed';

    public function handle(): int
    {
        $published = 0;

        foreach ([Post::class, Page::class] as $model) {
            // lazyById, not each(): each() pages through results with OFFSET, and
            // since publishing removes rows from this result set, it would skip items.
            $model::where('status', 'draft')
                ->whereNotNull('publish_at')
                ->where('publish_at', '<=', now())
                ->lazyById()
                ->each(function ($item) use (&$published) {
                    $item->status = 'published';
                    $item->published_at ??= now();
                    $item->publish_at = null;
                    $item->save();
                    $published++;
                });
        }

        // Only log when something happened; this runs every minute.
        if ($published > 0) {
            Log::info("Scheduled publishing: published {$published} item(s).");
        }
        $this->info("Published {$published} scheduled item(s).");

        return self::SUCCESS;
    }
}
