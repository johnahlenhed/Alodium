<?php

namespace App\Services;

use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Support\FooterBackground;
use App\Support\NavBackground;
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
            'nav_background_color' => $this->navBackground($settings),
            'footer_background_color' => $this->footerBackground($settings),
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

        return [
            'brand' => ThemeOptions::resolveBrand($settings->brand_color),
            'fontBody' => $pick($settings->font_body, ThemeOptions::FONTS, ThemeOptions::DEFAULTS['fontBody']),
            'fontHeading' => $pick($settings->font_heading, ThemeOptions::FONTS, ThemeOptions::DEFAULTS['fontHeading']),
            'corners' => $pick($settings->corners, ThemeOptions::CORNERS, ThemeOptions::DEFAULTS['corners']),
            'buttons' => $pick($settings->buttons, ThemeOptions::BUTTONS, ThemeOptions::DEFAULTS['buttons']),
        ];
    }

    /**
     * The header's background colour, or null: also when it no longer passes with
     * the brand colour the site uses (changed in the database, say), so the header
     * then keeps the page colour rather than getting unreadable text.
     */
    private function navBackground(SiteSetting $settings): ?string
    {
        $value = $settings->nav_background_color;

        return NavBackground::scheme($value, ThemeOptions::resolveBrand($settings->brand_color)) !== null ? $value : null;
    }

    /** The footer's background colour, or null, sent on the same terms as the header's. */
    private function footerBackground(SiteSetting $settings): ?string
    {
        $value = $settings->footer_background_color;

        return FooterBackground::scheme($value, ThemeOptions::resolveBrand($settings->brand_color)) !== null ? $value : null;
    }

    /** The PNG conversion or the sanitised SVG, at the chosen height, or null (SiteSetting::logoImage()). */
    private function logo(SiteSetting $settings): ?array
    {
        $logo = $settings->logoImage();

        // Absolute, like og_image: url() leaves a full URL alone and completes a relative one.
        return $logo ? ['url' => url($logo['url']), 'width' => $logo['width'], 'height' => $logo['height']] : null;
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
