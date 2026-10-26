// Site name, navigation and footer text: the only place they are defined.
// The site settings feature will replace this module's values with data from
// the Laravel API; until then the name comes from SITE_NAME in astro/.env.
// (src/lib/jsonld.js reads the same SITE_NAME for the structured data.)

export const SITE = {
    // ?. so this module can also be imported outside Astro/Vite (the node checks).
    name: import.meta.env?.SITE_NAME || 'Alodium',
    navigation: [
        { label: 'Home', href: '/' },
        { label: 'Blog', href: '/blog' },
        { label: 'Contact', href: '/contact' },
    ],
    footerNote: 'This site uses cookie-free analytics.',
};

const trimSlash = (path) => (path.length > 1 ? path.replace(/\/+$/, '') : path);

/**
 * aria-current for a navigation link: "page" on the page itself, "true" inside
 * its section (a blog post for "Blog"), otherwise undefined (no attribute).
 */
export function currentState(href, pathname) {
    const link = trimSlash(href);
    const page = trimSlash(pathname);
    if (page === link) return 'page';
    if (link !== '/' && page.startsWith(`${link}/`)) return 'true';
    return undefined;
}
