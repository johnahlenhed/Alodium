<?php

namespace App\Services\Sanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The only CSS allowed in stored HTML: text-align (left, center or right) on
 * paragraphs and headings, as produced by the TipTap TextAlign extension.
 * Any other declaration is dropped, and a style without a valid text-align is
 * removed completely. Arbitrary CSS would let content overlay or restyle the page.
 */
class TextAlignStyleSanitizer implements AttributeSanitizerInterface
{
    public const ELEMENTS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function getSupportedElements(): ?array
    {
        return self::ELEMENTS;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        foreach (explode(';', $value) as $declaration) {
            if (preg_match('/^\s*text-align\s*:\s*(left|center|right)\s*$/i', $declaration, $match)) {
                return 'text-align: '.strtolower($match[1]);
            }
        }

        return null;
    }
}
