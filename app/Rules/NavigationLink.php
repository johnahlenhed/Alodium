<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A navigation link: a path on this site ("/blog") or an http(s) address. The
 * same check as SafeLink, without mailto: and tel:, which don't belong in a
 * menu. astro/src/lib/settings.js accepts exactly these too.
 */
class NavigationLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 2048 || ! SafeLink::isValid($value) || preg_match('/^(mailto|tel):/i', $value)) {
            $fail('The :attribute must be a path on this site starting with / (such as /blog) or an http(s) address.');
        }
    }
}
