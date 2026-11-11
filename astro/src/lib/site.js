// Defaults for the site settings, which come from GET /api/public/site (the
// Settings page in the admin). A default is only used when that one field is
// missing or invalid in the response (see src/lib/settings.js); when the
// request itself fails, the build fails instead of falling back to these.

export const SITE_DEFAULTS = Object.freeze({
    name: 'Alodium',
    description: null,
    footerText: null,
    showSiteName: true,
    // buildTheme() in src/lib/theme.js fills in every theme default.
    theme: Object.freeze({}),
    /** The header's colour: { color, scheme: 'light' | 'dark' }, or null for the page colour. */
    navBackground: null,
    /** The footer's colour, the same way: { color, scheme }, or null for the light grey. */
    footerBackground: null,
    /** How the header and footer are arranged (src/lib/layouts.js). */
    headerLayout: 'standard',
    footerLayout: 'standard',
    /** The header's padding above and below its content, in px: 12 (0.75rem) is what it always had. */
    headerPadding: 12,
    logo: null,
    navigation: Object.freeze([
        Object.freeze({ label: 'Home', href: '/' }),
        Object.freeze({ label: 'Blog', href: '/blog' }),
        Object.freeze({ label: 'Contact', href: '/contact' }),
    ]),
    frontPage: null,
});
