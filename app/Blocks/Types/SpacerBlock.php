<?php

namespace App\Blocks\Types;

use App\Blocks\BlockType;
use App\Services\ContentSanitizer;
use App\Support\ColorContrast;
use Closure;

/**
 * Empty vertical space between two blocks, optionally filled with a colour.
 *
 * It has no text, so unlike the page, header and footer background colours its
 * colour needs no contrast check: any #RRGGBB is allowed. On the site it is
 * spaced like any other block, so it adds its height plus one usual gap between
 * blocks and changes no other block's spacing. The same bounds are in astro/src/lib/spacer.js and
 * resources/js/Components/Blocks/spacer.js (SiteThemeSyncTest).
 */
class SpacerBlock extends BlockType
{
    /**
     * In px: from a hairline (with a colour, a thin rule across the page) to about
     * a third of a laptop screen. The editor's slider moves in steps of 2, so it
     * lands on every even height, the default included.
     */
    public const HEIGHT_MIN = 2;

    public const HEIGHT_MAX = 240;

    /** About two thirds of the smallest usual gap between blocks (--space-block, 48 to 72 px). */
    public const HEIGHT_DEFAULT = 32;

    /**
     * The colour's opacity, in percent. 100, fully opaque, is how every spacer
     * looked before the field, so one stored without it is unchanged. Without a
     * colour there is nothing to apply it to, so it is stored as the default.
     */
    public const OPACITY_MIN = 0;

    public const OPACITY_MAX = 100;

    public const OPACITY_DEFAULT = 100;

    public function name(): string
    {
        return 'spacer';
    }

    public function rules(): array
    {
        return [
            // numeric too: "integer" alone lets true through as 1, rejected here only because the
            // floor is 2; numeric rejects booleans whatever the bounds. The same for opacity below.
            'height' => ['nullable', 'integer', 'numeric', 'between:'.self::HEIGHT_MIN.','.self::HEIGHT_MAX],
            'background_color' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                // The same format check as the brand and background colours, without their contrast rule.
                if (! is_string($value) || ! preg_match(ColorContrast::HEX_PATTERN, $value)) {
                    $fail('Enter the colour as # followed by 6 hexadecimal digits, for example #F5F1EA.');
                }
            }],
            // For opacity, true as 1 is inside 0 to 100, so without numeric it would be stored.
            'opacity' => ['nullable', 'integer', 'numeric', 'between:'.self::OPACITY_MIN.','.self::OPACITY_MAX],
        ];
    }

    public function attributes(): array
    {
        return [
            'height' => 'spacer height',
            'background_color' => 'spacer background colour',
            'opacity' => 'spacer opacity',
        ];
    }

    /**
     * A missing or cleared height or opacity becomes the default; the colour is
     * stored in capitals, as a page's is. The opacity is only kept with a colour.
     */
    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        $height = $data['height'] ?? null;
        $color = $this->text($data, 'background_color');
        $opacity = $data['opacity'] ?? null;

        return [
            'height' => $height === null || $height === '' ? self::HEIGHT_DEFAULT : (int) $height,
            'background_color' => $color === null ? null : strtoupper($color),
            'opacity' => $color === null || $opacity === null || $opacity === '' ? self::OPACITY_DEFAULT : (int) $opacity,
        ];
    }
}
