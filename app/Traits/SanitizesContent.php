<?php

namespace App\Traits;

use App\Services\ContentSanitizer;

/**
 * Shared by PostController and PageController. Astro outputs `content` with
 * set:html, so it is sanitised on every save, not only on WordPress import:
 * otherwise a crafted request from an editor account could store a script on
 * the public site. Page blocks are sanitised separately (BlockRegistry).
 */
trait SanitizesContent
{
    /** Sanitises `content` in validated input, only when it was sent. */
    protected function sanitizeContent(array $validated): array
    {
        if (isset($validated['content']) && is_string($validated['content'])) {
            $validated['content'] = app(ContentSanitizer::class)->sanitize($validated['content']);
        }

        return $validated;
    }
}
