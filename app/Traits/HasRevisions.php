<?php

namespace App\Traits;

use App\Models\Revision;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Revision history for published content.
 *
 * A revision is a snapshot of the version that is about to be replaced. It is
 * taken in the "updating" event, before the new values are written, and only
 * when the version being replaced was published and its title or content is
 * changing. Drafts are exploratory and not versioned, so the first publish of a
 * draft creates no revision; that first published version becomes a revision
 * the first time it is edited. The current version always lives on the model.
 */
trait HasRevisions
{
    /** Revisions kept per post/page; older ones are deleted. */
    public const REVISIONS_TO_KEEP = 20;

    public static function bootHasRevisions(): void
    {
        static::updating(function (self $model) {
            $wasPublished = $model->getOriginal('status') === 'published';

            if ($wasPublished && $model->isDirty(['title', 'content'])) {
                $model->saveRevision($model->getOriginal('title'), $model->getOriginal('content'));
            }
        });

        // revisions has no foreign key (it is polymorphic), so clean up by hand.
        static::deleting(fn (self $model) => $model->revisions()->delete());
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(Revision::class, 'model')->latest('id');
    }

    /**
     * Stores a snapshot (the current saved values unless given), skipping it if it
     * is identical to the newest revision, then prunes to the newest 20.
     */
    public function saveRevision(?string $title = null, ?string $content = null): ?Revision
    {
        $title ??= $this->getOriginal('title');
        $content ??= $this->getOriginal('content');

        $latest = $this->revisions()->first();
        if ($latest && $latest->title === $title && $latest->content === $content) {
            return null;
        }

        $revision = $this->revisions()->create(['title' => $title, 'content' => $content]);

        $keep = $this->revisions()->limit(self::REVISIONS_TO_KEEP)->pluck('id');
        $this->revisions()->whereNotIn('id', $keep)->delete();

        return $revision;
    }
}
