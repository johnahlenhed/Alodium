// Build-time access to the Laravel public content API.
// These functions only run while Astro builds the site, never in the browser.

import { normalizeSite } from './settings.js';

const API_URL = (import.meta.env.API_URL ?? '').replace(/\/+$/, '');

/**
 * Origin of the Laravel app (e.g. "http://localhost:8000"), where uploaded files
 * live under /storage/. Used to make relative image paths from the API absolute.
 */
export const apiOrigin = (() => {
    try {
        return new URL(API_URL).origin;
    } catch {
        return '';
    }
})();

export async function api(path) {
    if (!API_URL) {
        throw new Error('API_URL is not set. Copy astro/.env.example to astro/.env.');
    }

    const url = `${API_URL}${path}`;
    let response;
    try {
        response = await fetch(url, { headers: { Accept: 'application/json' } });
    } catch (error) {
        // Expected when Laravel isn't running: fail the build with a clear message.
        throw new Error(`Could not reach the Laravel API at ${url}. Is "php artisan serve" running? (${error.message})`);
    }

    if (!response.ok) {
        throw new Error(`Laravel API returned ${response.status} for ${url}`);
    }

    try {
        return await response.json();
    } catch (error) {
        throw new Error(`Laravel API returned invalid JSON for ${url} (${error.message})`);
    }
}

export const getPosts = () => api('/posts');
export const getPost = (slug) => api(`/posts/${encodeURIComponent(slug)}`);
export const getPages = () => api('/pages');
export const getPage = (slug) => api(`/pages/${encodeURIComponent(slug)}`);

let site = null;

/**
 * The site settings (name, theme, logo, navigation, footer, front page), checked
 * by src/lib/settings.js. Fetched once per build: every page shares the same
 * promise. A failed request or a response that isn't a JSON object fails the
 * build (the rebuild script then keeps the previous site live); it never falls
 * back to the defaults. Only single invalid fields do.
 */
export function getSite() {
    site ??= api('/site').then((raw) => {
        try {
            return normalizeSite(raw, { apiOrigin });
        } catch (error) {
            throw new Error(`Laravel API returned unusable site settings for ${API_URL}/site: ${error.message}`);
        }
    });
    return site;
}

/** "16 October 2026" */
export function formatDate(iso) {
    return iso
        ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
        : '';
}
