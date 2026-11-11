<?php

namespace App\Support;

/**
 * The UTM values Alodium keeps, and how one is cleaned before it is stored.
 *
 * Only utm_source, utm_medium and utm_campaign are kept: short labels that say
 * where a visitor came from (newsletter, email, autumn-sale). utm_term and
 * utm_content are never read or stored. They are unused, and they are the two
 * parameters where email and ad tools most often put subscriber-level
 * identifiers. That is a data-minimisation choice: the columns existed and were
 * dropped (2026_11_11_110000).
 *
 * A value is cleaned, never refused: a bad one becomes null and the page view is
 * still counted. The same rules run in the browser before anything is sent
 * (public/tracker.js) and in the link builder (resources/js/utils/utm.js);
 * tests/Fixtures/utm-values.json holds the examples all three must agree on.
 */
final class UtmValue
{
    /** The parameters that are read and stored, in this order. */
    public const FIELDS = ['utm_source', 'utm_medium', 'utm_campaign'];

    /** Longer than any real label; a longer value is cut to this many characters. */
    public const MAX_LENGTH = 100;

    /**
     * Control characters and invisible formatting characters (zero-width, bidi),
     * removed. Tab and line breaks (U+0009 to U+000D) are left for SPACES.
     */
    private const INVISIBLE = '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

    /** Whitespace, including the Unicode spaces, collapsed to one space. */
    private const SPACES = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    /**
     * The three values to store from a tracker request: each cleaned, or null.
     * Anything else in the request (utm_term, utm_content) is not looked at.
     *
     * @return array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}
     */
    public static function fromInput(array $input): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = self::clean($input[$field] ?? null);
        }

        return $values;
    }

    /**
     * A label as stored: invisible characters removed, whitespace collapsed and
     * trimmed, lowercase (so "Newsletter" and "newsletter" are one source), at
     * most MAX_LENGTH characters. Null for anything that isn't a string, is
     * empty, or looks like it identifies a person rather than a campaign
     * (identifying()).
     */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = preg_replace(self::INVISIBLE, '', $value);
        $value = mb_strtolower(trim(preg_replace(self::SPACES, ' ', $value)));

        if ($value === '' || self::identifying($value)) {
            return null;
        }

        return rtrim(mb_substr($value, 0, self::MAX_LENGTH));
    }

    /**
     * Whether a (lowercase) value looks like an email address or an opaque
     * token rather than a label: an "@"; a run of 7 or more digits (subscriber,
     * customer or phone numbers); or a "word" (letters and digits between other
     * characters) that is 24 or more characters long, or 16 or more mixing
     * letters and digits (hashes, click ids), or 8 or more hexadecimal
     * characters mixing letters and digits (UUIDs, list ids). A heuristic: it
     * also drops numeric campaign ids and dates written as 20261111.
     */
    public static function identifying(string $value): bool
    {
        if (str_contains($value, '@') || preg_match('/\d{7,}/', $value)) {
            return true;
        }

        foreach (preg_split('/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $length = strlen($word);
            $mixed = preg_match('/[a-z]/', $word) && preg_match('/\d/', $word);
            if ($length >= 24 || ($mixed && $length >= 16) || ($mixed && $length >= 8 && ctype_xdigit($word))) {
                return true;
            }
        }

        return false;
    }
}
