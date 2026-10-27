<?php

namespace App\Services\Sanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Removes a protocol-relative <img src> ("//other.host/x.jpg"). The sanitiser
 * allows relative media so that uploads can be stored as "/storage/...", but
 * "//" is not relative to this site: it loads from any host. Browsers read
 * "/\host" and "\\host" the same way, so those are removed too. The attribute
 * is dropped (the image stays, without a source), the same as for a src with
 * a scheme that isn't allowed. The WordPress importer turns "//" into
 * "https://" before sanitising, so imported images are kept.
 */
class ImageSourceSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $start = substr(ltrim($value, " \t\n\r\0\x0B\f"), 0, 2);

        return in_array($start, ['//', '/\\', '\\/', '\\\\'], true) ? null : $value;
    }
}
