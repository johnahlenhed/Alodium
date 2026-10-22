<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Readable text from stored HTML, for excerpts and descriptions. */
class PlainText
{
    public static function fromHtml(?string $html): string
    {
        // A space at block boundaries first, so "</p><p>" doesn't glue words together.
        $text = strip_tags(preg_replace('/<(\/p|br\s*\/?|\/h[1-6]|\/li|\/blockquote|\/pre|hr\s*\/?)>/i', '$0 ', $html ?? '') ?? '');

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)) ?? '');
    }

    public static function excerpt(string $text, int $length): string
    {
        return Str::limit($text, $length);
    }
}
