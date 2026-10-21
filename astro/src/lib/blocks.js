// Helpers for rendering page blocks. The server already validates and sanitises
// block data; these checks are a second line of defence at render time.

import { apiOrigin } from './api.js';

/**
 * A safe href, or null: http(s), mailto:, tel:, or a path on this site starting
 * with "/" (not "//"). `external` is true for http(s) links (they get rel="noopener noreferrer").
 */
export function safeLink(value) {
    if (typeof value !== 'string') return null;
    const url = value.trim();
    // Control characters and whitespace can hide a scheme ("java\tscript:").
    if (url === '' || /[\x00-\x20\x7F\\]/.test(url)) return null;

    if (url.startsWith('/')) {
        return url.startsWith('//') ? null : { href: url, external: false };
    }

    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return null;
    }

    if (parsed.protocol === 'http:' || parsed.protocol === 'https:') return { href: url, external: true };
    if (parsed.protocol === 'mailto:' || parsed.protocol === 'tel:') return { href: url, external: false };

    return null;
}

/**
 * An absolute image URL, or null. Allows http(s) URLs and "/storage/..." paths,
 * which are made absolute with the Laravel origin (uploads are served by Laravel).
 */
export function safeImage(value) {
    if (typeof value !== 'string' || value === '' || /[\s\\]/.test(value)) return null;

    if (value.startsWith('/storage/')) {
        return apiOrigin ? `${apiOrigin}${value}` : null;
    }

    try {
        const parsed = new URL(value);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? value : null;
    } catch {
        return null;
    }
}

const isText = (value) => typeof value === 'string' && value.trim() !== '';

/**
 * Required fields per implemented block type. A block that fails its check is
 * skipped by BlockRenderer with a warning. Returns null when valid, otherwise why not.
 */
export const BLOCK_CHECKS = {
    hero: (data) => (isText(data.heading) ? null : 'heading is missing'),
    text: (data) => (typeof data.html === 'string' ? null : 'html is missing'),
    image: (data) => (safeImage(data.image) ? null : 'image is missing or not an allowed URL'),
};

/**
 * Whether a block can be rendered: its type has a component and its required data
 * is present. Returns { ok: true } or { ok: false, reason }. Used by BlockRenderer
 * (which warns and skips) and by the page template (to find the first rendered block).
 */
export function checkBlock(block) {
    const type = block?.type;
    if (!Object.hasOwn(BLOCK_CHECKS, type)) {
        return { ok: false, reason: `type "${type}" has no component yet` };
    }

    const data = block && typeof block.data === 'object' && block.data !== null ? block.data : {};
    const problem = BLOCK_CHECKS[type](data);

    return problem ? { ok: false, reason: `${type} block: ${problem}` } : { ok: true };
}

/** The first block that will actually be rendered, or undefined. */
export const firstRenderedBlock = (blocks) => (Array.isArray(blocks) ? blocks.find((block) => checkBlock(block).ok) : undefined);
