<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the public site's main navigation (after Home, which is fixed).
 * Links to a page (label null = the page's title) or to a URL: a path on the
 * site such as /blog, or an http(s) address. Edited on the Settings page.
 */
class NavigationItem extends Model
{
    protected $fillable = ['label', 'page_id', 'url', 'sort_order'];

    protected $casts = [
        'page_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
