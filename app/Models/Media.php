<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    // Laravel would infer "media" from "Media" anyway; stated for clarity.
    protected $table = 'media';

    protected $fillable = [
        'user_id',
        'filename',
        'original_filename',
        'mime_type',
        'size',
        'disk',
        'path',
        'url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
