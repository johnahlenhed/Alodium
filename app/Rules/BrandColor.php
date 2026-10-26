<?php

namespace App\Rules;

use App\Support\ColorContrast;
use App\Support\ThemeOptions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A #rrggbb colour that white text can sit on: at least 4.5:1 against white,
 * the same check as isValidBrand() in astro/src/lib/theme.js.
 */
class BrandColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(ColorContrast::HEX_PATTERN, $value)) {
            $fail('Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.');

            return;
        }

        $ratio = ColorContrast::againstWhite($value);

        if ($ratio < ThemeOptions::MIN_BRAND_CONTRAST) {
            $fail(sprintf(
                'This colour is too light: white text on it would be hard to read (contrast %s:1, minimum %s:1). Try a darker shade.',
                ColorContrast::format($ratio),
                number_format(ThemeOptions::MIN_BRAND_CONTRAST, 1),
            ));
        }
    }
}
