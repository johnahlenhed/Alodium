// Checks the site settings from GET /api/public/site again before the build
// uses them (the server validated them too), and the navigation helpers.
// Plain functions without import.meta.env, so the node checks can use them.
//
// A missing or invalid field falls back to its default (src/lib/site.js) with a
// build warning. Text stays text: the templates output it with Astro's normal
// escaping, never set:html.

import { SITE_DEFAULTS } from './site.js';
import { DEFAULT_SETTINGS, resolveSettings } from './theme.js';

/** The server's limits (App\Http\Requests\UpdateSiteSettingsRequest). */
export const LIMITS = Object.freeze({ name: 80, description: 300, footerText: 200, logoSide: 4000 });

const CONTROL_CHARACTERS = /\p{Cc}/u;
const characters = (text) => [...text].length; // code points, like the server's mb_strlen

/** A path on this site: starts with "/", not "//", no backslash, whitespace or control characters. */
export function isSitePath(href) {
    return typeof href === 'string' && href.startsWith('/') && !href.startsWith('//') && !/[\x00-\x20\x7F\\]/.test(href);
}

/** "/about/" -> "/about"; "/" stays "/". Query and fragment are ignored. */
export function normalizePath(href) {
    const path = href.replace(/[?#].*$/, '');
    return path.length > 1 ? path.replace(/\/+$/, '') : path;
}

/**
 * The API response as the build uses it. Throws only when the response isn't a
 * JSON object at all; every field on its own falls back to its default.
 */
export function normalizeSite(raw, { apiOrigin, warn = (message) => console.warn(`[site] ${message}`) }) {
    if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
        throw new Error('the site settings response is not a JSON object');
    }

    const text = (key, field, max, required) => {
        const value = raw[key];
        if (value === null && !required) return null;
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed === '' && !required) return null;
            if (trimmed !== '' && characters(trimmed) <= max && !CONTROL_CHARACTERS.test(trimmed)) return trimmed;
        }
        warn(`"${key}" is missing or invalid (${required ? '1-' : 'null or up to '}${max} characters of plain text); using the default.`);
        return SITE_DEFAULTS[field];
    };

    let showSiteName = SITE_DEFAULTS.showSiteName;
    if (typeof raw.show_site_name === 'boolean') {
        showSiteName = raw.show_site_name;
    } else {
        warn('"show_site_name" is missing or not a boolean; using the default.');
    }

    return {
        name: text('name', 'name', LIMITS.name, true),
        description: text('description', 'description', LIMITS.description, false),
        footerText: text('footer_text', 'footerText', LIMITS.footerText, false),
        showSiteName,
        theme: theme(raw.theme, warn),
        logo: logo(raw.logo, apiOrigin, warn),
        navigation: navigation(raw.navigation, warn),
        frontPage: frontPage(raw.front_page, warn),
    };
}

/** Through theme.js's allow-lists; each value that had to be replaced is reported. */
function theme(value, warn) {
    const resolved = resolveSettings(value);
    const given = value !== null && typeof value === 'object' && !Array.isArray(value) ? value : {};
    for (const key of Object.keys(DEFAULT_SETTINGS)) {
        const original = Object.hasOwn(given, key) ? given[key] : undefined;
        const same = typeof original === 'string' && (key === 'brand' ? original.toLowerCase() : original) === resolved[key];
        if (!same) warn(`theme.${key} is missing or not allowed; using the default "${resolved[key]}".`);
    }
    return resolved;
}

/** An absolute http(s) URL on the API's own origin, with a size from 1 to 4000 px; otherwise no logo. */
function logo(value, apiOrigin, warn) {
    if (value === null) return null;

    const side = (n) => Number.isInteger(n) && n >= 1 && n <= LIMITS.logoSide;
    let url = null;
    try {
        url = typeof value?.url === 'string' ? new URL(value.url) : null;
    } catch {
        url = null;
    }

    const ok = url && (url.protocol === 'http:' || url.protocol === 'https:') && url.origin === apiOrigin && side(value.width) && side(value.height);
    if (!ok) {
        warn('"logo" is invalid (it needs an http(s) URL on the API origin and a width and height from 1 to 4000); the site is built without it.');
        return null;
    }
    return { url: url.href, width: value.width, height: value.height };
}

/** Entries with a text label and a path on this site, in the order given; others are dropped. */
function navigation(value, warn) {
    if (!Array.isArray(value)) {
        warn('"navigation" is missing or not a list; using the default navigation.');
        return SITE_DEFAULTS.navigation;
    }

    return value.flatMap((item, index) => {
        const label = typeof item?.label === 'string' ? item.label.trim() : '';
        if (label === '' || CONTROL_CHARACTERS.test(label) || !isSitePath(item?.href)) {
            warn(`navigation entry ${index} is dropped: it needs a text label and an href starting with "/" (not "//").`);
            return [];
        }
        return [{ label, href: item.href }];
    });
}

function frontPage(value, warn) {
    if (value === null) return null;
    const slug = value?.slug;
    if (typeof slug === 'string' && slug !== '' && isSitePath(`/${slug}`) && !/[/?#]/.test(slug)) return { slug };
    warn('"front_page" is invalid; the home page shows the latest posts.');
    return null;
}

/** The navigation without the front page's own address: the front page is "Home" already. */
export function navigationFor(site) {
    if (!site.frontPage) return site.navigation;
    const frontPath = normalizePath(`/${site.frontPage.slug}`);
    return site.navigation.filter((item) => normalizePath(item.href) !== frontPath);
}

/**
 * aria-current for a navigation link: "page" on the page itself, "true" inside
 * its section (a blog post for "Blog"), otherwise undefined (no attribute).
 */
export function currentState(href, pathname) {
    const link = normalizePath(href);
    const page = normalizePath(pathname);
    if (page === link) return 'page';
    if (link !== '/' && page.startsWith(`${link}/`)) return 'true';
    return undefined;
}
