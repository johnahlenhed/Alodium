<?php

namespace App\Support;

/**
 * The theme choices for the public site: the one PHP list of allowed values
 * and defaults, used by validation, the Settings page and the public API.
 *
 * They must equal astro/src/lib/theme.js and fonts.js (the Astro side
 * validates the values again). SiteThemeSyncTest loads those modules with
 * Node and compares, so the two can't drift apart unnoticed.
 */
final class ThemeOptions
{
    /** Option => label. */
    public const FONTS = [
        'inter' => 'Inter',
        'serif' => 'Source Serif 4',
        'system' => 'System font',
    ];

    /** Option => [label, radius]. The radius is only for the admin preview; Astro has its own table. */
    public const CORNERS = [
        'sharp' => ['label' => 'Sharp', 'radius' => '0px'],
        'soft' => ['label' => 'Soft', 'radius' => '6px'],
        'round' => ['label' => 'Round', 'radius' => '14px'],
    ];

    public const BUTTONS = [
        'solid' => 'Solid',
        'outline' => 'Outline',
    ];

    /** The defaults, in the API's (and theme.js's) key names. */
    public const DEFAULTS = [
        'brand' => '#1B2A4A',
        'fontBody' => 'inter',
        'fontHeading' => 'inter',
        'corners' => 'soft',
        'buttons' => 'solid',
    ];

    /** White text on the brand colour needs at least this contrast (MIN_BRAND_CONTRAST in theme.js). */
    public const MIN_BRAND_CONTRAST = 4.5;
}
