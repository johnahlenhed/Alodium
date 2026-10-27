<?php

namespace App\Services;

use App\Services\Sanitizer\ImageSourceSanitizer;
use App\Services\Sanitizer\TextAlignStyleSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The one HTML allowlist for stored rich text: WordPress imports and page block
 * HTML (text blocks, columns). It keeps everything the TipTap editor produces and
 * removes everything else (scripts, event handlers, javascript: links, iframes, ...).
 */
class ContentSanitizer
{
    /** Elements kept as-is, with only the listed attributes. */
    private const ALLOWED = [
        'p' => ['style'], 'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'],
        'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'],
        'ul' => [], 'ol' => [], 'li' => [],
        'strong' => [], 'em' => [], 's' => [], 'u' => [],
        'blockquote' => [], 'pre' => [], 'code' => [], 'hr' => [], 'br' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /**
     * Wrapper/inline elements that are removed but whose text is kept. Without this,
     * the sanitizer would drop e.g. a whole <div> or <table> including its content.
     * Elements in neither list (script, style, iframe, form, ...) are dropped entirely.
     */
    private const UNWRAP = [
        'div', 'span', 'section', 'article', 'header', 'footer', 'aside', 'main', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'b', 'i',
        'del', 'ins', 'small', 'sub', 'sup', 'mark', 'abbr', 'cite', 'q', 'dl', 'dt', 'dd',
        'center', 'font', 'label', 'time',
    ];

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            // Relative media for uploads ("/storage/..."); protocol-relative "//host" is removed below.
            ->allowRelativeMedias()
            ->withAttributeSanitizer(new TextAlignStyleSanitizer)
            ->withAttributeSanitizer(new ImageSourceSanitizer)
            // Default is 20 000 bytes, which would silently truncate long posts.
            ->withMaxInputLength(-1);

        foreach (self::ALLOWED as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }
        foreach (self::UNWRAP as $element) {
            $config = $config->blockElement($element);
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
