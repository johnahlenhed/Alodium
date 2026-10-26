<?php

namespace App\Services;

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
            'navigation' => $this->navigation($settings),
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
     * Home, the chosen pages in their order, then Blog and Contact when enabled.
     * Only pages on the public site; hrefs without a trailing slash, like the
     * links in the Astro templates.
     */
    private function navigation(SiteSetting $settings): array
    {
        $pages = Page::onPublicSite()
            ->where('show_in_nav', true)
            ->orderBy('nav_order')
            ->orderBy('id')
            ->get(['title', 'slug'])
            ->map(fn (Page $page) => ['label' => $page->title, 'href' => '/'.$page->slug])
            ->all();

        return [
            ['label' => 'Home', 'href' => '/'],
            ...$pages,
            ...($settings->show_blog_in_nav ? [['label' => 'Blog', 'href' => '/blog']] : []),
            ...($settings->show_contact_in_nav ? [['label' => 'Contact', 'href' => '/contact']] : []),
        ];
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
