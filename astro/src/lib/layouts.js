// How the header and footer are arranged (site_settings.header_layout and
// footer_layout, from the Settings page): a short fixed list for each. Every
// layout but the default is a modifier class in src/styles/site.css
// (site-header--centered, site-footer--columns, ...) that only rearranges what
// is already there: no layout adds content or changes a colour, so the
// background colour checks hold for all of them (scripts/check-header-footer.mjs).
//
// Must equal App\Support\LayoutOptions (SiteThemeSyncTest).

/** The arrangement both had before the setting: no modifier class. */
export const DEFAULT_LAYOUT = 'standard';

export const HEADER_LAYOUTS = Object.freeze(['standard', 'centered-menu', 'centered', 'stacked']);

export const FOOTER_LAYOUTS = Object.freeze(['standard', 'columns', 'centered']);

/**
 * The modifier class for a layout of `block` ("site-header" or "site-footer"),
 * or null for the default and for anything not in `layouts`. Array.includes, so
 * "constructor" and the like never match.
 */
export function layoutClass(block, layouts, layout) {
    return typeof layout === 'string' && layout !== DEFAULT_LAYOUT && layouts.includes(layout) ? `${block}--${layout}` : null;
}
