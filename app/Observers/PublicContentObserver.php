<?php

namespace App\Observers;

use App\Jobs\RebuildAstroJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Queues a rebuild of the Astro site whenever a change is visible on it.
 * Lives on the models (Post, Page) rather than in the controllers, so every
 * way of changing content is covered: admin forms, the WordPress importer,
 * and anything added later.
 */
class PublicContentObserver
{
    public function created(Model $model): void
    {
        if ($model->status === 'published') {
            RebuildAstroJob::dispatch();
        }
    }

    /**
     * Only fires when an UPDATE query actually ran. (The "saved" event also fires
     * for a save with no changes, and wasChanged() then still reports the changes
     * of the previous save, which would cause needless rebuilds.)
     */
    public function updated(Model $model): void
    {
        // getPrevious() holds the values from before this update.
        $wasPublished = ($model->getPrevious()['status'] ?? $model->status) === 'published';

        // Published, edited while published, or unpublished.
        if ($model->status === 'published' || $wasPublished) {
            RebuildAstroJob::dispatch();
        }
    }

    public function deleted(Model $model): void
    {
        if ($model->status === 'published') {
            RebuildAstroJob::dispatch();
        }
    }
}
