<?php

namespace App\Rules;

use App\Support\ColorContrast;
use App\Support\PageBackground;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A page background: a #rrggbb colour (as for the brand colour, BrandColor) on
 * which everything on the page stays readable with the site's brand colour
 * (App\Support\PageBackground, the same check as the public site's).
 */
class PageBackgroundColor implements ValidationRule
{
    public function __construct(private string $brand) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(ColorContrast::HEX_PATTERN, $value)) {
            $fail('Enter the colour as # followed by 6 hexadecimal digits, for example #F5F1EA.');

            return;
        }

        $worst = PageBackground::worst($value, $this->brand);
        if ($worst !== null) {
            $fail(sprintf(
                'This colour is too dark for the page: %s would be hard to read on it (contrast %s:1, minimum %s:1). Try a lighter shade.',
                $worst['what'],
                ColorContrast::format($worst['ratio']),
                number_format($worst['minimum'], 1),
            ));
        }
    }
}
