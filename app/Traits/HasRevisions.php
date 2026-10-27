<?php

namespace App\Traits;

use App\Models\Revision;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Revision history for published content.
 *
 * A revision is a snapshot of the version that is about to be replaced. It is
 * taken in the "updating" event, before the new values are written, and only
 * when the version being replaced was published and its title, content or
 * blocks (pages) are changing. Drafts are exploratory and not versioned, so the first publish of a
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

            // isDirty() compares a cast array by value, so re-saving identical blocks is not a change.
            if ($wasPublished && $model->isDirty(['title', 'content', 'blocks'])) {
                $model->saveRevision();
            }
        });

        // revisions has no foreign key (it is polymorphic), so clean up by hand.
        // A function body, not an arrow function: "deleting" listeners stop at the
        // first one that returns a non-null value, and delete() returns a count, so
        // Spatie's listener (which deletes the media rows and files) never ran.
        static::deleting(function (self $model): void {
            $model->revisions()->delete();
        });
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(Revision::class, 'model')->latest('id');
    }

    /**
     * Stores a snapshot of the saved (original) title, content and blocks, skipping
     * it if it is identical to the newest revision, then prunes to the newest 20.
     * Called before an update, the original values are the version being replaced.
     */
    public function saveRevision(): ?Revision
    {
        $title = $this->getOriginal('title');
        $content = $this->getOriginal('content');
        // Posts have no blocks column, so this is null for them.
        $blocks = $this->getOriginal('blocks');

        $latest = $this->revisions()->first();
        if ($latest && $latest->title === $title && $latest->content === $content && $latest->blocks == $blocks) {
            return null;
        }

        $revision = $this->revisions()->create(['title' => $title, 'content' => $content, 'blocks' => $blocks]);

        $keep = $this->revisions()->limit(self::REVISIONS_TO_KEEP)->pluck('id');
        $this->revisions()->whereNotIn('id', $keep)->delete();

        return $revision;
    }
}
