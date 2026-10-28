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

/** The variants (data.variant) the hero and the panel blocks (text, columns) offer, besides "default". */
export const HERO_VARIANTS = ['light', 'dark'];
export const PANEL_VARIANTS = ['light', 'brand', 'dark'];

/**
 * "block--dark" for a variant in `allowed`, otherwise null ("default", a missing
 * value, or one this block doesn't offer): only known class names reach the HTML.
 */
export const variantClass = (variant, allowed) => (allowed.includes(variant) ? `block--${variant}` : null);

/** Class attribute from a list, leaving out empty entries. */
export const classes = (...names) => names.filter(Boolean).join(' ');

export const isText = (value) => typeof value === 'string' && value.trim() !== '';

/** Visible text of sanitised HTML, for emptiness checks. */
export const htmlText = (html) => (typeof html === 'string' ? html.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').trim() : '');

/** A column has content when it has text (or an inline image) or an allowed image. */
export const hasColumnContent = (column) =>
    Boolean(column) && (Boolean(safeImage(column.image)) || htmlText(column.html) !== '' || /<img\b/i.test(column.html ?? ''));
