<?php

namespace App\Support;

use DOMDocument;

/**
 * The size an SVG is drawn at by an <img>: its width and height attributes,
 * otherwise its viewBox. Only the proportions matter to the callers (the logo
 * is scaled to 128px high), so absolute units are converted to px and relative
 * ones (%, em) count as missing.
 */
final class SvgSize
{
    /** CSS absolute units in px. */
    private const UNITS = ['' => 1, 'px' => 1, 'pt' => 4 / 3, 'pc' => 16, 'mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96];

    /** [width, height], both above 0, or null when the SVG doesn't say how big it is. */
    public static function of(string $svg): ?array
    {
        $dom = new DOMDocument;
        // Called on sanitised SVG (no DOCTYPE, so no entities); LIBXML_NONET all the same.
        if ($svg === '' || ! @$dom->loadXML($svg, LIBXML_NONET)) {
            return null;
        }
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'svg') {
            return null;
        }

        $width = self::length($root->getAttribute('width'));
        $height = self::length($root->getAttribute('height'));
        if ($width !== null && $height !== null) {
            return [$width, $height];
        }

        $box = self::viewBox($root->getAttribute('viewBox'));
        if ($box === null) {
            return null;
        }
        // One of width and height: the other follows the viewBox's proportions.
        [$boxWidth, $boxHeight] = $box;

        return match (true) {
            $width !== null => [$width, $width * $boxHeight / $boxWidth],
            $height !== null => [$height * $boxWidth / $boxHeight, $height],
            default => $box,
        };
    }

    /** "240", "240px", "2.5in" -> px; null for a missing, relative or non-positive length. */
    private static function length(string $value): ?float
    {
        if (! preg_match('/^\s*(\d*\.?\d+(?:e[+-]?\d+)?)\s*(px|pt|pc|mm|cm|in)?\s*$/i', $value, $match)) {
            return null;
        }
        $px = (float) $match[1] * self::UNITS[strtolower($match[2] ?? '')];

        return $px > 0 && is_finite($px) ? $px : null;
    }

    /** "0 0 240 80" or "0,0,240,80" -> [240, 80]; null unless the width and height are above 0. */
    private static function viewBox(string $value): ?array
    {
        $parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) !== 4 || array_filter($parts, fn (string $part) => ! is_numeric($part))) {
            return null;
        }
        [, , $width, $height] = array_map('floatval', $parts);

        return $width > 0 && $height > 0 && is_finite($width) && is_finite($height) ? [$width, $height] : null;
    }
}
