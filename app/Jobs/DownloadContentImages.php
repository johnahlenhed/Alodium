<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\Page;
use App\Models\Post;
use App\Services\ImageDownload\ImageDownloadFailed;
use App\Services\ImportedImageStore;
use App\Support\ContentImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads the images in one imported post or page and points the content at
 * the local copies (/storage/...), and downloads and attaches its featured
 * image. Queued per item by the WordPress importer when "download images" is on.
 *
 * - An image that can't be downloaded is skipped and its reason recorded on the
 *   run; it never fails the job, and the content keeps its original URL.
 * - Content is written with the query builder: no revision, no rebuild per
 *   item, and updated_at stays as it was. The run queues one rebuild at the end.
 * - The write only happens if the content is unchanged since it was read
 *   (an edit in the admin meanwhile wins; the rewrite is redone once on it).
 * - After job_time_budget seconds the job queues itself for the rest, so it
 *   always ends well within the worker's --timeout=660.
 * - Running it again finds nothing left to do: rewritten images are local, and
 *   a featured image already attached is not downloaded again.
 */
class DownloadContentImages implements ShouldQueue
{
    use Queueable;

    /** Under the worker's --timeout (660); the time budget below keeps it far from this. */
    public int $timeout = 600;

    /** A crash (not an image failure) is retried once; failed() then closes the item. */
    public int $tries = 2;

    private const TABLES = ['post' => 'posts', 'page' => 'pages'];

    private const MODELS = ['post' => Post::class, 'page' => Page::class];

    /**
     * @param  'post'|'page'  $type
     * @param  list<string>  $skip  URLs that already failed in an earlier part of this item (not retried)
     * @param  int  $attempted  images already handled for this item (for max_images_per_item)
     * @param  string|null  $featuredUrl  the featured image (from the export's attachments), done first
     */
    public function __construct(
        public int $runId,
        public string $type,
        public int $itemId,
        public array $skip = [],
        public int $attempted = 0,
        public ?string $featuredUrl = null,
    ) {}

    public function handle(ImportedImageStore $store): void
    {
        $run = ImportRun::find($this->runId);
        if (! $run) {
            return;
        }

        $started = microtime(true);
        $table = self::TABLES[$this->type];
        $row = DB::table($table)->where('id', $this->itemId)->first(['id', 'title', 'content']);
        if (! $row) {
            $run->recordFailure($this->label(null), null, 'the item was deleted before its images were downloaded', countsAsSkipped: false);
            $run->finishItem();

            return;
        }

        $label = $this->label($row->title);
        $hosts = $run->allowed_hosts ?? [];
        $candidates = array_values(array_diff(ContentImages::candidates($row->content, $hosts), $this->skip));

        $map = [];
        $failed = [];
        $attempted = $this->attempted;
        $outOfTime = false;
        $budget = (int) config('import.job_time_budget');
        $perItem = (int) config('import.max_images_per_item');

        if ($this->featuredUrl !== null) {
            $attempted++;
            $this->attachFeatured($store, $run, $label.' (featured image)');
        }

        foreach ($candidates as $url) {
            if ($attempted >= $perItem) {
                $run->recordFailure($label, $url, "item limit: more than {$perItem} images");
                $failed[] = $url;

                continue;
            }
            // Leave room for one more download (timeout) and its conversions. At least
            // one image per job, so a requeued job always makes progress.
            if ($attempted > $this->attempted && microtime(true) - $started > $budget - (int) config('import.timeout') - 15) {
                $outOfTime = true;
                break;
            }

            $attempted++;
            try {
                $map[$url] = $store->localPath($run, $url);
            } catch (ImageDownloadFailed $e) {
                $run->recordFailure($label, $url, $e->reason());
                $failed[] = $url;
            } catch (Throwable $e) {
                Log::warning('Import image download failed unexpectedly', ['url' => $url, 'error' => $e->getMessage()]);
                $run->recordFailure($label, $url, 'unexpected error');
                $failed[] = $url;
            }
        }

        if ($map !== []) {
            $this->writeBack($run, $table, $row->content, $map, $hosts, $label);
        }

        if ($outOfTime) {
            // The rest in a new job; images done so far (and the featured image) are already written.
            self::dispatch($this->runId, $this->type, $this->itemId, [...$this->skip, ...$failed], $attempted);

            return;
        }

        $run->finishItem();
    }

    /**
     * The featured image goes into the item's 'featured' collection, the way the
     * importer attached it before (see ImportedImageStore::attachFeatured). Loading
     * the model and adding media saves nothing on it: no revision, no rebuild.
     */
    private function attachFeatured(ImportedImageStore $store, ImportRun $run, string $label): void
    {
        $record = (self::MODELS[$this->type])::find($this->itemId);
        if (! $record) {
            return;
        }

        try {
            $store->attachFeatured($run, $record, $this->featuredUrl);
        } catch (ImageDownloadFailed $e) {
            $run->recordFailure($label, $this->featuredUrl, $e->reason());
        } catch (Throwable $e) {
            Log::warning('Import featured image failed unexpectedly', ['url' => $this->featuredUrl, 'error' => $e->getMessage()]);
            $run->recordFailure($label, $this->featuredUrl, 'unexpected error');
        }
    }

    /** A crash after the retry: the item is closed so the run can still finish. */
    public function failed(?Throwable $exception): void
    {
        $run = ImportRun::find($this->runId);
        if (! $run) {
            return;
        }
        $run->recordFailure($this->label(null), null, 'the image step for this item failed: '.($exception?->getMessage() ?? 'unknown error'), countsAsSkipped: false);
        $run->finishItem();
    }

    /**
     * Compare-and-set: the UPDATE only matches if the content is still what was
     * read. If it changed meanwhile (an edit in the admin), the rewrite is done
     * once more on the new content; if that changes too, the edit wins and the
     * images stay in the library for a later run.
     */
    private function writeBack(ImportRun $run, string $table, string $original, array $map, array $hosts, string $label): void
    {
        $current = $original;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $rewritten = ContentImages::rewrite($current, $map, $hosts);
            if ($rewritten === $current) {
                return;
            }

            $written = DB::table($table)->where('id', $this->itemId)->where('content', $current)->update(['content' => $rewritten]);
            if ($written === 1) {
                return;
            }

            $current = DB::table($table)->where('id', $this->itemId)->value('content');
            if ($current === null) {
                return;
            }
        }

        $run->recordFailure($label, null, 'the content was edited during the download, so it was not rewritten', countsAsSkipped: false);
    }

    private function label(?string $title): string
    {
        return $this->type.' "'.($title ?? '#'.$this->itemId).'"';
    }
}
