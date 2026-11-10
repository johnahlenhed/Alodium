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

    public function name(): string
    {
        return 'spacer';
    }

    public function rules(): array
    {
        return [
            'height' => ['nullable', 'integer', 'between:'.self::HEIGHT_MIN.','.self::HEIGHT_MAX],
            'background_color' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                // The same format check as the brand and background colours, without their contrast rule.
                if (! is_string($value) || ! preg_match(ColorContrast::HEX_PATTERN, $value)) {
                    $fail('Enter the colour as # followed by 6 hexadecimal digits, for example #F5F1EA.');
                }
            }],
        ];
    }

    public function attributes(): array
    {
        return [
            'height' => 'spacer height',
            'background_color' => 'spacer background colour',
        ];
    }

    /** A missing or cleared height becomes the default; the colour is stored in capitals, as a page's is. */
    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        $height = $data['height'] ?? null;
        $color = $this->text($data, 'background_color');

        return [
            'height' => $height === null || $height === '' ? self::HEIGHT_DEFAULT : (int) $height,
            'background_color' => $color === null ? null : strtoupper($color),
        ];
    }
}
