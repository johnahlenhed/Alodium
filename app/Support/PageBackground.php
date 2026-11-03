<?php

namespace App\Support;

/**
 * Which background colours a page can have: any #rrggbb colour on which the
 * text, links, buttons and form parts that sit straight on the page stay
 * readable. Written to match astro/src/lib/pageBackground.js step by step, so
 * the server accepts exactly the colours the public site shows;
 * PageBackgroundTest checks that against tests/Fixtures/page-backgrounds.json,
 * generated from the JavaScript.
 */
final class PageBackground
{
    /** The fixed colours on a page background, as in astro/src/styles/blocks.css. */
    private const TEXT_COLOUR = '#1c2026';

    private const MUTED_COLOUR = '#59606c';

    private const BORDER_STRONG_COLOUR = '#7c8494';

    private const SUCCESS_COLOUR = '#1d7a3e';

    private const ERROR_COLOUR = '#b3261e';

    private const TEXT = 4.5;

    private const UI = 3.0;

    /**
     * Every check for this background and brand (both #rrggbb), in the same order
     * as pageBackgroundChecks(): [['what', 'ratio', 'minimum', 'ok'], ...].
     *
     * @return list<array{what: string, ratio: float, minimum: float, ok: bool}>
     */
    public static function checks(string $background, string $brand): array
    {
        $bg = ColorContrast::parseHex($background);
        $rgb = ColorContrast::parseHex($brand);
        // --color-brand-strong and --color-brand-deep, mixed as color.js mix() does.
        $strong = self::mix($rgb, [0, 0, 0], 0.75);
        $deep = self::mix($rgb, [0, 0, 0], 0.55);

        $pairs = [
            ['the text', ColorContrast::parseHex(self::TEXT_COLOUR), self::TEXT],
            ['muted text (dates, captions)', ColorContrast::parseHex(self::MUTED_COLOUR), self::TEXT],
            ['links and button text', $strong, self::TEXT],
            ['links on hover', $deep, self::TEXT],
            ["the contact form's success message", ColorContrast::parseHex(self::SUCCESS_COLOUR), self::TEXT],
            ["the contact form's error messages", ColorContrast::parseHex(self::ERROR_COLOUR), self::TEXT],
            ['the focus ring', $strong, self::UI],
            ['form field borders', ColorContrast::parseHex(self::BORDER_STRONG_COLOUR), self::UI],
            ['button edges', $rgb, self::UI],
        ];

        return array_map(function (array $pair) use ($bg): array {
            [$what, $colour, $minimum] = $pair;
            $ratio = ColorContrast::ratio($colour, $bg);

            return ['what' => $what, 'ratio' => $ratio, 'minimum' => $minimum, 'ok' => $ratio >= $minimum];
        }, $pairs);
    }

    /** The check furthest below its minimum (the first of equals), or null when all pass. */
    public static function worst(string $background, string $brand): ?array
    {
        $worst = null;
        foreach (self::checks($background, $brand) as $check) {
            if (! $check['ok'] && ($worst === null || $check['ratio'] / $check['minimum'] < $worst['ratio'] / $worst['minimum'])) {
                $worst = $check;
            }
        }

        return $worst;
    }

    /** A #rrggbb colour that every check passes on, for this brand colour. */
    public static function isValid(mixed $value, string $brand): bool
    {
        return is_string($value) && preg_match(ColorContrast::HEX_PATTERN, $value)
            && preg_match(ColorContrast::HEX_PATTERN, $brand) && self::worst($value, $brand) === null;
    }

    /** color.js mix(): each channel a * weight + b * (1 - weight), unrounded. */
    private static function mix(array $a, array $b, float $weight): array
    {
        return array_map(fn (int $i) => $a[$i] * $weight + $b[$i] * (1 - $weight), [0, 1, 2]);
    }
}
