<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link target that is safe to put in href: http(s), mailto:, tel:, or a path on
 * this site starting with "/". Anything else (javascript:, data:, vbscript:,
 * protocol-relative "//host", ...) is rejected.
 */
class SafeLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 2048 || ! self::isValid($value)) {
            $fail('The :attribute must be an http(s), mailto: or tel: link, or a path starting with /.');
        }
    }

    public static function isValid(string $value): bool
    {
        // Control characters and whitespace can hide a scheme from naive checks ("java\tscript:").
        if ($value === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $value)) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return match ($scheme) {
            'http', 'https' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'mailto', 'tel' => strlen($value) > strlen($scheme) + 1,
            default => false,
        };
    }
}
