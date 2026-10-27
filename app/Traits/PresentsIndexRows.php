<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared by PostController and PageController: one row of the posts/pages list.
 *
 * An item the user may not edit (for an editor: another author's draft or
 * scheduled item, see ContentPolicy) is still listed, so the editor sees that
 * it exists, but only with what the list shows: title, status, dates and
 * author. Its content, blocks and meta fields never reach the browser, which
 * the edit page already refuses to show.
 */
trait PresentsIndexRows
{
    /** @param  Model  $item  a Post or Page with its author loaded */
    protected function indexRow(User $user, Model $item): array
    {
        $can = [
            'update' => $user->can('update', $item),
            'delete' => $user->can('delete', $item),
        ];

        if (! $can['update']) {
            return [
                ...$item->only(['id', 'title', 'status', 'publish_at', 'published_at']),
                'author' => $item->author?->only(['id', 'name']),
                'can' => $can,
            ];
        }

        return [...$item->toArray(), 'can' => $can];
    }
}
