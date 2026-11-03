<?php

namespace App\Rules;

use App\Support\ColorContrast;
use App\Support\NavBackground;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The header's background: a #rrggbb colour on which either dark or white text
 * works with this brand colour (App\Support\NavBackground, the same check as
 * the public site's). The message names the worst check of each.
 */
class NavBackgroundColor implements ValidationRule
{
    public function __construct(private string $brand) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(ColorContrast::HEX_PATTERN, $value)) {
            $fail('Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.');

            return;
        }

        $worst = NavBackground::worst($value, $this->brand);
        if ($worst['light'] !== null && $worst['dark'] !== null) {
            $describe = fn (array $check) => sprintf('%s at %s:1 (minimum %s:1)', $check['what'], ColorContrast::format($check['ratio']), number_format($check['minimum'], 1));
            $fail(sprintf(
                'Neither dark nor white text works on this colour. With dark text, %s; with white text, %s. Try a lighter or a darker shade.',
                $describe($worst['light']),
                $describe($worst['dark']),
            ));
        }
    }
}
