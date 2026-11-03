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

    /**
     * The height the public site shows the logo at (logo_height, in px): 36 is
     * what the header always used (2.25rem). The range keeps the header usable;
     * astro/src/lib/settings.js has the same bounds (SiteThemeSyncTest).
     */
    public const LOGO_HEIGHT_DEFAULT = 36;

    public const LOGO_HEIGHT_MIN = 24;

    public const LOGO_HEIGHT_MAX = 96;

    /**
     * The PNG conversion's maximum height: twice the largest shown height, so the
     * logo stays sharp on high-density screens. SVG logos store their proportions
     * at this height too. The width follows the aspect ratio.
     */
    public const LOGO_PIXEL_HEIGHT = 2 * self::LOGO_HEIGHT_MAX;

    /** The logo's maximum width, as stored and as shown (the public site accepts at most 4000). */
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
        'logo_height',
        'brand_color',
        'nav_background_color',
        'footer_background_color',
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
        'logo_height' => 'integer',
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
            'logo_height' => self::LOGO_HEIGHT_DEFAULT,
            'brand_color' => ThemeOptions::DEFAULTS['brand'],
            // null: the header has the page colour, as before this setting.
            'nav_background_color' => null,
            // null: the footer has the light grey surface, as before this setting.
            'footer_background_color' => null,
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
     * The logo as the site shows it: the PNG conversion, or for an SVG (sanitised
     * on upload) the file itself, at the chosen height with the width that keeps
     * its proportions. The URL is relative. null without a logo, and while a raster
     * logo's conversion is missing.
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

        // The stored size is the file's (or, for an SVG, its proportions at LOGO_PIXEL_HEIGHT).
        $width = (int) $media->getCustomProperty('logo_width');
        $height = (int) $media->getCustomProperty('logo_height');
        if ($width < 1 || $height < 1) {
            return null;
        }
        [$width, $height] = self::fitLogo($width, $height, $this->shownLogoHeight());

        return ['url' => $media->getUrl($conversion), 'width' => $width, 'height' => $height];
    }

    /**
     * logo_height within its bounds: null (a row from before the column) is the
     * default, and a value outside the range (changed in the database, say) is
     * clamped. The form request rejects one on save.
     */
    public function shownLogoHeight(): int
    {
        $height = $this->logo_height ?? self::LOGO_HEIGHT_DEFAULT;

        return max(self::LOGO_HEIGHT_MIN, min(self::LOGO_HEIGHT_MAX, $height));
    }

    /**
     * A logo of this size scaled to $height high, keeping its proportions, but at
     * most LOGO_MAX_WIDTH wide (as Fit::Max does for the conversion, though this
     * also scales up).
     *
     * @return array{int, int}
     */
    public static function fitLogo(float $width, float $height, int $targetHeight): array
    {
        $scale = min($targetHeight / $height, self::LOGO_MAX_WIDTH / $width);

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

        // At most LOGO_PIXEL_HEIGHT (192px) high, never upscaled (Fit::Max). PNG,
        // so transparency is kept whatever was uploaded. Generated during the
        // upload, so its URL and size are known before the rebuild that the save
        // queues. (Logos uploaded while it was 128px keep that conversion: sharp
        // on high-density screens up to 64px shown.)
        $this->addMediaConversion('logo')
            ->fit(Fit::Max, self::LOGO_MAX_WIDTH, self::LOGO_PIXEL_HEIGHT)
            ->format('png')
            ->performOnCollections('logo')
            ->nonQueued();
    }
}
