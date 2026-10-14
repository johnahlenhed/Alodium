<?php

namespace App\Models\Concerns;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Shared image conversions for every model that owns media. Spatie defines
 * conversions per model (not in config/media-library.php), so this trait keeps
 * them identical across Post, Page and User.
 *
 * All conversions output WebP. The original upload is kept untouched as the
 * source of truth, so conversions can be regenerated later
 * (php artisan media-library:regenerate) if sizes change.
 *
 * SVGs are skipped automatically: GD cannot rasterise them, and they are
 * already small, scalable vector files.
 */
trait RegistersImageConversions
{
    public function registerMediaConversions(?Media $media = null): void
    {
        // Generated during the upload request so the library grid can show it immediately.
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 400, 400)
            ->format('webp')
            ->nonQueued();

        // Larger sizes are queued (QUEUE_CONNECTION) to keep uploads and imports responsive.
        // Fit::Max = "contain" without upscaling small images.
        $this->addMediaConversion('medium')
            ->fit(Fit::Max, 1200, 1200)
            ->format('webp');

        // Full-size WebP version of the original.
        $this->addMediaConversion('webp')
            ->format('webp');
    }
}
