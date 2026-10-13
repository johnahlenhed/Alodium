<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait GeneratesUniqueSlug
{
    /**
     * The slug column is unique, but two records can share a title. Append -2, -3, ...
     * until free, ignoring the record being updated so it can keep its own slug.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueSlug(string $model, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'untitled';
        $slug = $base;
        $i = 2;

        while ($model::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
