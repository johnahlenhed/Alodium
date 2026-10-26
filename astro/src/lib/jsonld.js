// JSON-LD structured data (https://schema.org) for search engines and AI assistants.
// Each builder returns a plain object; BaseLayout serialises it with serializeJsonLd().
// siteName is the name from the site settings (getSite() in api.js).

/** Absolute URL for a path on this site (site = Astro.site, from SITE_URL). */
export const absoluteUrl = (path, site) => new URL(path, site).href;

/** "2026-10-20T14:00:00.000Z", or undefined (and then omitted) when missing. */
const isoDate = (value) => (value ? new Date(value).toISOString() : undefined);

const publisher = (site, siteName) => ({
    '@type': 'Organization',
    name: siteName,
    url: absoluteUrl('/', site),
});

/** Removes null, undefined and empty-string fields at every level. */
function clean(value) {
    if (Array.isArray(value)) return value.map(clean);
    if (value && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value)
                .filter(([, v]) => v !== null && v !== undefined && v !== '')
                .map(([k, v]) => [k, clean(v)]),
        );
    }
    return value;
}

export function blogPostingJsonLd({ post, excerpt, url, site, siteName }) {
    return clean({
        '@context': 'https://schema.org',
        '@type': 'BlogPosting',
        headline: post.title,
        description: post.meta_description || excerpt,
        url,
        datePublished: isoDate(post.published_at),
        dateModified: isoDate(post.updated_at ?? post.published_at),
        // The API returns the author's name as a string.
        author: post.author ? { '@type': 'Person', name: post.author } : undefined,
        publisher: publisher(site, siteName),
        image: post.og_image,
        mainEntityOfPage: { '@type': 'WebPage', '@id': url },
    });
}

export function webPageJsonLd({ page, excerpt, url, site, siteName }) {
    return clean({
        '@context': 'https://schema.org',
        '@type': 'WebPage',
        name: page.title,
        // The excerpt comes from the page list (the single-page API has none).
        description: page.meta_description || excerpt,
        url,
        publisher: publisher(site, siteName),
    });
}

/** The site itself, at "/". description is the site description from the settings, left out when null. */
export function webSiteJsonLd({ site, siteName, description }) {
    return clean({
        '@context': 'https://schema.org',
        '@type': 'WebSite',
        name: siteName,
        url: absoluteUrl('/', site),
        description,
    });
}

export function blogJsonLd({ url, site, siteName }) {
    return clean({
        '@context': 'https://schema.org',
        '@type': 'Blog',
        name: 'Blog',
        url,
        publisher: publisher(site, siteName),
    });
}

/**
 * JSON for an inline <script type="application/ld+json">. Plain JSON.stringify is
 * not safe there: a title containing "</script>" would end the tag early and let
 * HTML from the CMS run on the page. <, > and & are escaped as < etc., which
 * JSON parsers read back as the same characters.
 */
export function serializeJsonLd(data) {
    return JSON.stringify(data)
        .replace(/</g, '\\u003c')
        .replace(/>/g, '\\u003e')
        .replace(/&/g, '\\u0026')
        .replace(/\u2028/g, '\\u2028')
        .replace(/\u2029/g, '\\u2029');
}
