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
    logo: null,
    navigation: Object.freeze([
        Object.freeze({ label: 'Home', href: '/' }),
        Object.freeze({ label: 'Blog', href: '/blog' }),
        Object.freeze({ label: 'Contact', href: '/contact' }),
    ]),
    frontPage: null,
});
