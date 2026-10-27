<?php

namespace App\Services;

use enshrined\svgSanitize\Sanitizer;

/**
 * Cleans an uploaded SVG before it is stored. SVG files are served from the
 * admin domain, and an SVG opened directly in the browser runs its scripts
 * there, with the admin's session.
 *
 * enshrined/svg-sanitize keeps only an allowlist of SVG elements and attributes,
 * which removes <script>, <foreignObject> and every on* event handler, and drops
 * javascript: links. removeRemoteReferences() also removes references to other
 * sites (href="https://..."), so opening the file makes no outside requests.
 * It strips any DOCTYPE before parsing and parses with LIBXML_NONET, so entities
 * can't read local files either.
 */
class SvgSanitizer
{
    /** The cleaned SVG, or null when the file can't be read as SVG. */
    public function sanitize(string $svg): ?string
    {
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);

        $clean = $sanitizer->sanitize($svg);

        return is_string($clean) && trim($clean) !== '' ? $clean : null;
    }
}
