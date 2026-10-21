<?php

namespace App\Traits;

use App\Models\Revision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/** Revision list and restore, shared by PostController and PageController. */
trait HandlesRevisions
{
    /** Newest first: id, title, excerpt, created_at. */
    protected function revisionList(Model $model): array
    {
        return $model->revisions()
            ->get()
            ->map(fn (Revision $revision) => [
                'id' => $revision->id,
                'title' => $revision->title,
                'excerpt' => $revision->excerpt(),
                'created_at' => $revision->created_at,
            ])
            ->all();
    }

    /**
     * Replaces the content (not the title or meta fields) with the revision's:
     * `content`, and for pages also `blocks`. A revision of a legacy page has
     * blocks = null, so restoring it turns the page back into a legacy page.
     * The current content is saved as a revision first, so a restore can itself
     * be undone by restoring that revision.
     */
    protected function restore(Model $model, Revision $revision, string $editRoute): RedirectResponse
    {
        $model->saveRevision();
        $model->update([
            'content' => $revision->content,
            ...($model->isFillable('blocks') ? ['blocks' => $revision->blocks] : []),
        ]);

        return redirect()
            ->route($editRoute, $model)
            ->with('success', 'Restored the version from '.$revision->created_at->format('Y-m-d H:i').' (UTC). The previous content was saved as a revision.');
    }
}
