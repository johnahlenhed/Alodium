<?php

namespace App\Models;

use App\Models\Concerns\RegistersImageConversions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Page extends Model implements HasMedia
{
    use InteractsWithMedia, RegistersImageConversions {
        // Both traits define registerMediaConversions(); ours holds the real conversions.
        RegistersImageConversions::registerMediaConversions insteadof InteractsWithMedia;
    }

    protected $fillable = [
        'author_id',
        'title',
        'slug',
        'content',
        'meta_title',
        'meta_description',
        'og_image',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function registerMediaCollections(): void
    {
        // One featured image per page; adding a new one replaces the old.
        $this->addMediaCollection('featured')->singleFile();
    }
}
