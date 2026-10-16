// Build-time access to the Laravel public content API.
// These functions only run while Astro builds the site, never in the browser.

const API_URL = (import.meta.env.API_URL ?? '').replace(/\/+$/, '');

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

    return response.json();
}

export const getPosts = () => api('/posts');
export const getPost = (slug) => api(`/posts/${encodeURIComponent(slug)}`);
export const getPages = () => api('/pages');
export const getPage = (slug) => api(`/pages/${encodeURIComponent(slug)}`);

/** "16 October 2026" */
export function formatDate(iso) {
    return iso
        ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
        : '';
}
