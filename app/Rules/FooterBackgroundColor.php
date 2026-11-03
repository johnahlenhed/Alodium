<?php

namespace App\Rules;

use App\Support\ColorContrast;
use App\Support\ContrastChecks;
use App\Support\FooterBackground;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The footer's background: a #rrggbb colour on which either dark or white text
 * works with this brand colour (App\Support\FooterBackground, the same check as
 * the public site's). The message names the worst check of each, as for the
 * header (NavBackgroundColor).
 */
class FooterBackgroundColor implements ValidationRule
{
    public function __construct(private string $brand) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(ColorContrast::HEX_PATTERN, $value)) {
            $fail('Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.');

            return;
        }

        $message = ContrastChecks::schemeMessage(FooterBackground::worst($value, $this->brand));
        if ($message !== null) {
            $fail($message);
        }
    }
}
