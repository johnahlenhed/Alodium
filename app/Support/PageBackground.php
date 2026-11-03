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

    /** The minimums, as in pageBackground.js: WCAG 1.4.3 (normal text) and 1.4.11 (focus rings, borders). */
    public const TEXT = 4.5;

    public const UI = 3.0;

    /**
     * Every check for this background and brand (both #rrggbb), in the same order
     * as pageBackgroundChecks(): [['what', 'ratio', 'minimum', 'ok'], ...].
     *
     * @return list<array{what: string, ratio: float, minimum: float, ok: bool}>
     */
    public static function checks(string $background, string $brand): array
    {
        $rgb = ColorContrast::parseHex($brand);
        // --color-brand-strong and --color-brand-deep, mixed as color.js mix() does.
        $strong = ColorContrast::mix($rgb, [0, 0, 0], 0.75);
        $deep = ColorContrast::mix($rgb, [0, 0, 0], 0.55);

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

        return ContrastChecks::run($pairs, $background);
    }

    /** The check furthest below its minimum (the first of equals), or null when all pass. */
    public static function worst(string $background, string $brand): ?array
    {
        return ContrastChecks::worst(self::checks($background, $brand));
    }

    /** A #rrggbb colour that every check passes on, for this brand colour. */
    public static function isValid(mixed $value, string $brand): bool
    {
        return is_string($value) && preg_match(ColorContrast::HEX_PATTERN, $value)
            && preg_match(ColorContrast::HEX_PATTERN, $brand) && self::worst($value, $brand) === null;
    }
}
