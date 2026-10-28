<?php

namespace App\Services;

use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Support\ColorContrast;
use App\Support\ThemeOptions;

/**
 * What the public site gets from the settings (GET /api/public/site): plain
 * values only, never CSS or HTML. Text is returned exactly as stored; escaping
 * is the Astro templates' job, and Astro validates the theme values again.
 *
 * The same array decides whether a save needs a rebuild: if it is identical
 * before and after, nothing visible changed (SiteSettingsController).
 */
class PublicSiteSettings
{
    public function payload(): array
    {
        $settings = SiteSetting::current();

        return [
            'name' => $settings->site_name,
            'description' => $settings->site_description,
            'footer_text' => $settings->footer_text,
            'show_site_name' => $settings->show_site_name,
            'theme' => $this->theme($settings),
            'logo' => $this->logo($settings),
            'navigation' => $this->navigation(),
            'front_page' => $this->frontPage($settings),
        ];
    }

    /**
     * The theme values. They were validated on save; checking them against the
     * allow-list again means a value changed directly in the database still
     * can't reach the public site (which checks them a third time).
     */
    private function theme(SiteSetting $settings): array
    {
        $pick = fn (?string $value, array $allowed, string $default) => is_string($value) && array_key_exists($value, $allowed) ? $value : $default;
        $brand = $settings->brand_color;
        $validBrand = is_string($brand) && preg_match(ColorContrast::HEX_PATTERN, $brand)
            && ColorContrast::againstWhite($brand) >= ThemeOptions::MIN_BRAND_CONTRAST;

        return [
            'brand' => $validBrand ? strtoupper($brand) : ThemeOptions::DEFAULTS['brand'],
            'fontBody' => $pick($settings->font_body, ThemeOptions::FONTS, ThemeOptions::DEFAULTS['fontBody']),
            'fontHeading' => $pick($settings->font_heading, ThemeOptions::FONTS, ThemeOptions::DEFAULTS['fontHeading']),
            'corners' => $pick($settings->corners, ThemeOptions::CORNERS, ThemeOptions::DEFAULTS['corners']),
            'buttons' => $pick($settings->buttons, ThemeOptions::BUTTONS, ThemeOptions::DEFAULTS['buttons']),
        ];
    }

    /** The 128px-high conversion with its size, or null (also while the conversion is missing). */
    private function logo(SiteSetting $settings): ?array
    {
        $media = $settings->logo();
        if (! $media || ! $media->hasGeneratedConversion('logo')) {
            return null;
        }

        return [
            // Absolute, like og_image: url() leaves a full URL alone and completes a relative one.
            'url' => url($media->getUrl('logo')),
            'width' => (int) $media->getCustomProperty('logo_width'),
            'height' => (int) $media->getCustomProperty('logo_height'),
        ];
    }

    /**
     * Home, then the navigation entries in their order. A page entry links to the
     * page (hrefs without a trailing slash, like the links in the Astro
     * templates) with its own label or else the page's title; it is left out
     * while the page isn't on the public site. A URL entry is a path on the site
     * (Blog is "/blog") or an http(s) address.
     */
    private function navigation(): array
    {
        $entries = NavigationItem::query()
            ->with(['page' => fn ($query) => $query->onPublicSite()->select(['id', 'title', 'slug'])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->flatMap(function (NavigationItem $item) {
                if ($item->page_id !== null) {
                    return $item->page ? [['label' => $item->label ?? $item->page->title, 'href' => '/'.$item->page->slug]] : [];
                }

                // Validated on save; an incomplete row changed in the database is left out.
                return filled($item->label) && filled($item->url) ? [['label' => $item->label, 'href' => $item->url]] : [];
            })
            ->all();

        return [['label' => 'Home', 'href' => '/'], ...$entries];
    }

    /** null means "latest posts": none chosen, or the page is no longer on the site. */
    private function frontPage(SiteSetting $settings): ?array
    {
        if (! $settings->front_page_id) {
            return null;
        }

        $slug = Page::onPublicSite()->whereKey($settings->front_page_id)->value('slug');

        return $slug ? ['slug' => $slug] : null;
    }
}
