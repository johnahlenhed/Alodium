<?php

namespace App\Models;

use App\Support\ThemeOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The public site's identity and look, edited on the Settings page and served
 * by GET /api/public/site. A single row (id 1). Until it is first saved,
 * current() returns the defaults without writing anything.
 */
class SiteSetting extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** The one row. A fixed id, so two first saves can't create two rows. */
    public const ID = 1;

    /** Uploaded logos: content-checked by the form request, and again by Spatie. */
    public const LOGO_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];

    /** The logo's maximum height as shown; the width follows the aspect ratio. */
    public const LOGO_HEIGHT = 128;

    /** ... and its maximum width (the public site accepts at most 4000). */
    public const LOGO_MAX_WIDTH = 4000;

    /**
     * The name a new site has until it is renamed: the product's own, which is
     * APP_NAME in .env.example (the default site_name). The dashboard's Getting
     * started list asks for a real one.
     */
    public const PLACEHOLDER_NAME = 'Alodium';

    protected $fillable = [
        'site_name',
        'site_description',
        'footer_text',
        'show_site_name',
        'brand_color',
        'font_body',
        'font_heading',
        'corners',
        'buttons',
        'show_blog_in_nav',
        'show_contact_in_nav',
        'front_page_id',
    ];

    protected $casts = [
        'show_site_name' => 'boolean',
        'show_blog_in_nav' => 'boolean',
        'show_contact_in_nav' => 'boolean',
        'front_page_id' => 'integer',
    ];

    /** Every default, in one place. The theme values come from ThemeOptions (= astro/src/lib/theme.js). */
    public static function defaults(): array
    {
        return [
            'site_name' => (string) config('app.name'),
            'site_description' => null,
            'footer_text' => null,
            'show_site_name' => true,
            'brand_color' => ThemeOptions::DEFAULTS['brand'],
            'font_body' => ThemeOptions::DEFAULTS['fontBody'],
            'font_heading' => ThemeOptions::DEFAULTS['fontHeading'],
            'corners' => ThemeOptions::DEFAULTS['corners'],
            'buttons' => ThemeOptions::DEFAULTS['buttons'],
            'show_blog_in_nav' => true,
            'show_contact_in_nav' => true,
            'front_page_id' => null,
        ];
    }

    /** The saved settings, or an unsaved model holding the defaults (nothing is written). */
    public static function current(): self
    {
        $saved = static::query()->with('media')->find(self::ID);
        if ($saved) {
            return $saved;
        }

        $settings = new static(static::defaults());
        $settings->id = self::ID;

        return $settings;
    }

    public function frontPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'front_page_id');
    }

    /** The current logo, or null. Unsaved defaults never have one. */
    public function logo(): ?Media
    {
        return $this->exists ? $this->getFirstMedia('logo') : null;
    }

    /**
     * The logo as the site shows it, with its size: the PNG conversion, or for an
     * SVG (sanitised on upload) the file itself. The URL is relative. null without
     * a logo, and while a raster logo's conversion is missing.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    public function logoImage(): ?array
    {
        $media = $this->logo();
        $conversion = $media?->mime_type === 'image/svg+xml' ? '' : 'logo';
        if (! $media || ($conversion !== '' && ! $media->hasGeneratedConversion($conversion))) {
            return null;
        }

        return [
            'url' => $media->getUrl($conversion),
            'width' => (int) $media->getCustomProperty('logo_width'),
            'height' => (int) $media->getCustomProperty('logo_height'),
        ];
    }

    /**
     * The size an SVG logo is shown at: scaled to fit LOGO_MAX_WIDTH x LOGO_HEIGHT,
     * as the raster conversion is (Fit::Max), but also scaled up, since a vector
     * loses nothing by it.
     *
     * @return array{int, int}
     */
    public static function svgLogoSize(float $width, float $height): array
    {
        $scale = min(self::LOGO_HEIGHT / $height, self::LOGO_MAX_WIDTH / $width);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    public function registerMediaCollections(): void
    {
        // One logo; uploading a new one replaces (and deletes) the old.
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(self::LOGO_MIME_TYPES);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // An SVG logo is shown as it is (see logoImage()). Imagick, if installed,
        // would otherwise let Spatie rasterise it.
        if ($media?->mime_type === 'image/svg+xml') {
            return;
        }

        // At most 128px high, never upscaled (Fit::Max). PNG, so transparency is
        // kept whatever was uploaded. Generated during the upload, so its URL and
        // size are known before the rebuild that the save queues.
        $this->addMediaConversion('logo')
            ->fit(Fit::Max, self::LOGO_MAX_WIDTH, self::LOGO_HEIGHT)
            ->format('png')
            ->performOnCollections('logo')
            ->nonQueued();
    }
}
