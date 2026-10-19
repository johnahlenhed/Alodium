<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Revision extends Model
{
    // Only created_at, filled in by the database.
    public $timestamps = false;

    protected $fillable = [
        'model_type',
        'model_id',
        'title',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** The post or page this revision belongs to. */
    public function revisionable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }

    /** First 100 characters of the content as plain text. */
    public function excerpt(int $length = 100): string
    {
        // A space at block boundaries first, so "</p><p>" doesn't glue words together.
        $text = strip_tags(preg_replace('/<(\/p|br\s*\/?|\/h[1-6]|\/li|\/blockquote)>/i', '$0 ', $this->content ?? '') ?? '');
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)) ?? '');

        return Str::limit($text, $length);
    }
}
