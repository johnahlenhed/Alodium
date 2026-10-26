<?php

namespace App\Support;

/**
 * WCAG 2 contrast, written to match astro/src/lib/color.js step by step (same
 * formula, same order of operations), so the server accepts exactly the brand
 * colours the public site accepts. BrandColorTest checks that against a
 * fixture generated from color.js.
 */
final class ColorContrast
{
    // \z, not $: in PHP, $ also matches before a trailing newline.
    public const HEX_PATTERN = '/^#[0-9a-fA-F]{6}\z/';

    /** "#1B2A4A" -> [27, 42, 74]. Only call with a value that matches HEX_PATTERN. */
    public static function parseHex(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** WCAG 2 relative luminance. */
    public static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(function (int|float $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** WCAG 2 contrast ratio, from 1 to 21. */
    public static function ratio(array $a, array $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /** Contrast of a #rrggbb colour against white. */
    public static function againstWhite(string $hex): float
    {
        return self::ratio(self::parseHex($hex), [255, 255, 255]);
    }

    /**
     * The ratio for display, rounded down to one decimal, so a colour that
     * fails at 4.47:1 is never shown as "4.5:1". The admin readout does the same.
     */
    public static function format(float $ratio): string
    {
        return number_format(floor($ratio * 10) / 10, 1);
    }
}
