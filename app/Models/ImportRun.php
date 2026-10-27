<?php

namespace App\Models;

use App\Jobs\RebuildAstroJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One WordPress import and the image downloads that follow it on the queue.
 * Several jobs update the same run at the same time, so every counter change
 * is a single atomic UPDATE, never a read-modify-write of the model.
 */
class ImportRun extends Model
{
    public const STATUS_IMPORTING = 'importing';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Failures kept per run; later ones are only counted. */
    public const MAX_FAILURES = 200;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'status',
        'download_images',
        'imported_posts',
        'imported_pages',
        'report',
        'items_total',
        'images_found',
        'allowed_hosts',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'download_images' => 'boolean',
        'report' => 'array',
        'failures' => 'array',
        'skipped_reasons' => 'array',
        'allowed_hosts' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Adds to a counter in one UPDATE (safe with jobs running in parallel). */
    public function addTo(string $counter, int $amount = 1): void
    {
        DB::table($this->getTable())->where('id', $this->id)->increment($counter, $amount);
    }

    /**
     * Takes one of the run's download slots, unless max_images_per_run is reached.
     * The check and the increment are one UPDATE, so two jobs can't both take the last slot.
     */
    public function reserveDownload(int $max): bool
    {
        return DB::table($this->getTable())
            ->where('id', $this->id)
            ->where('images_downloaded', '<', $max)
            ->increment('images_downloaded') === 1;
    }

    /** Gives a reserved slot back after a failed download. */
    public function releaseDownload(): void
    {
        DB::table($this->getTable())->where('id', $this->id)->where('images_downloaded', '>', 0)->decrement('images_downloaded');
    }

    /**
     * Records a skipped image (or, with $url null, a problem with a whole item):
     * counts it per reason, and keeps the first 200 entries. The row is locked
     * while the lists are changed, so parallel jobs don't overwrite each other's.
     */
    public function recordFailure(string $item, ?string $url, string $reason, bool $countsAsSkipped = true): void
    {
        DB::transaction(function () use ($item, $url, $reason, $countsAsSkipped) {
            $row = DB::table($this->getTable())->where('id', $this->id)->lockForUpdate()->first(['failures', 'skipped_reasons']);
            if (! $row) {
                return;
            }

            $failures = json_decode($row->failures ?? '[]', true) ?: [];
            $changes = [];
            if (count($failures) < self::MAX_FAILURES) {
                $failures[] = ['item' => $item, 'url' => $url, 'reason' => $reason];
                $changes['failures'] = json_encode($failures, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($countsAsSkipped) {
                $reasons = json_decode($row->skipped_reasons ?? '[]', true) ?: [];
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                $changes['skipped_reasons'] = json_encode($reasons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $changes['images_skipped'] = DB::raw('images_skipped + 1');
            }
            if ($changes) {
                DB::table($this->getTable())->where('id', $this->id)->update($changes);
            }
        });
    }

    /**
     * Marks one item as finished. The job that finishes the last item marks the
     * run done and queues the one rebuild: the status change is a conditional
     * UPDATE, so when the last two jobs finish at the same time only one of them
     * gets the row (affected rows = 1) and queues the rebuild.
     */
    public function finishItem(): void
    {
        $this->addTo('items_done');

        $finished = DB::table($this->getTable())
            ->where('id', $this->id)
            ->where('status', self::STATUS_DOWNLOADING)
            ->whereColumn('items_done', '>=', 'items_total')
            ->update(['status' => self::STATUS_DONE, 'finished_at' => now()]);

        if ($finished === 1) {
            RebuildAstroJob::dispatch();
        }
    }
}
