<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An image URL: an absolute http(s) URL (the same rule as og_image), or a path
 * to an uploaded file on this site, starting with "/storage/".
 */
class MediaUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 2048 || ! self::isValid($value)) {
            $fail('The :attribute must be an http(s) URL or a path starting with /storage/.');
        }
    }

    public static function isValid(string $value): bool
    {
        if (str_starts_with($value, '/storage/')) {
            return ! preg_match('/[\s\\\\]/', $value);
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
