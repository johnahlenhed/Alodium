<?php

namespace App\Support;

/**
 * Which background colours the site header can have, and with which text:
 * dark text on a light colour, or white text on a dark one; a colour must pass
 * every check of one of the two. Written to match astro/src/lib/navBackground.js
 * step by step; NavBackgroundTest checks that against
 * tests/Fixtures/nav-backgrounds.json, generated from the JavaScript.
 */
final class NavBackground
{
    /** The fixed colours on the header, as in astro/src/styles/blocks.css. */
    private const TEXT_COLOUR = '#1c2026';

    private const ON_DARK_COLOUR = '#ffffff';

    /** Tried in this order. */
    public const SCHEMES = ['light', 'dark'];

    /** Every check of one scheme ("light" or "dark") for this background and brand (both #rrggbb). */
    public static function checks(string $background, string $brand, string $scheme): array
    {
        if ($scheme === 'dark') {
            $white = ColorContrast::parseHex(self::ON_DARK_COLOUR);
            $pairs = [
                ['the site name and menu links', $white, PageBackground::TEXT],
                ['the focus ring', $white, PageBackground::UI],
            ];
        } else {
            $strong = ColorContrast::mix(ColorContrast::parseHex($brand), [0, 0, 0], 0.75);
            $pairs = [
                ['the site name and menu links', ColorContrast::parseHex(self::TEXT_COLOUR), PageBackground::TEXT],
                ['the current page and hovered links', $strong, PageBackground::TEXT],
                ['the focus ring', $strong, PageBackground::UI],
                ['the built-in logo mark', $strong, PageBackground::UI],
            ];
        }

        return ContrastChecks::run($pairs, $background);
    }

    /** The worst failing check of each scheme: ['light' => ?array, 'dark' => ?array]. */
    public static function worst(string $background, string $brand): array
    {
        $worst = [];
        foreach (self::SCHEMES as $scheme) {
            $worst[$scheme] = ContrastChecks::worst(self::checks($background, $brand, $scheme));
        }

        return $worst;
    }

    /** "light" (dark text), "dark" (white text) or null when neither passes. */
    public static function scheme(mixed $value, string $brand): ?string
    {
        if (! is_string($value) || ! preg_match(ColorContrast::HEX_PATTERN, $value) || ! preg_match(ColorContrast::HEX_PATTERN, $brand)) {
            return null;
        }
        $worst = self::worst($value, $brand);
        foreach (self::SCHEMES as $scheme) {
            if ($worst[$scheme] === null) {
                return $scheme;
            }
        }

        return null;
    }
}
