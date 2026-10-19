<?php

namespace App\Models\Concerns;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Shared image conversions for every model that owns media. Spatie defines
 * conversions per model (not in config/media-library.php), so this trait keeps
 * them identical across Post, Page and User.
 *
 * All conversions output WebP, except the Open Graph image (JPEG). The original upload is kept untouched as the
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

        // Social sharing image (Open Graph). 1200x630 is the size Facebook, LinkedIn and
        // X recommend, so the og:image:width/height tags on the public site are accurate.
        // JPEG rather than WebP: not every platform that reads og:image supports WebP.
        // Generated during the upload so it can be picked straight away.
        $this->addMediaConversion('og')
            ->fit(Fit::Crop, 1200, 630)
            ->format('jpg')
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
