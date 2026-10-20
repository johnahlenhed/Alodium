<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for posts and pages.
 * Admins: everything. Editors: create, edit their own items, and edit anyone's
 * published items, but not other authors' drafts or scheduled items, and never delete.
 */
abstract class ContentPolicy
{
    public function update(User $user, Model $item): bool
    {
        return $user->isAdmin()
            || $item->author_id === $user->id
            || $item->status === 'published';
    }

    public function delete(User $user, Model $item): bool
    {
        return $user->isAdmin();
    }
}
