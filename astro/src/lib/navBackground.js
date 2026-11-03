// Which background colours the site header can have (site_settings.
// nav_background_color), and with which text: dark text on a light colour, as
// the header has now, or white text on a dark one. A colour must pass every
// check of one of the two. With dark text, the current page, hovered links,
// the focus ring and the built-in mark use the darker brand shade, as on a
// coloured page. An uploaded logo is an image and isn't checked.
//
// Used by the build (SiteHeader), the Settings page's live readout and,
// mirrored, the server (App\Support\NavBackground, kept equal by
// tests/Fixtures/nav-backgrounds.json). scripts/check-contrast.mjs checks the
// colours below against blocks.css.

import { HEX_PATTERN, mix, parseHex } from './color.js';
import { checkPairs, worstCheck } from './contrastChecks.js';
import { TEXT, UI } from './pageBackground.js';

/** The fixed colours on the header, as in blocks.css (--color-text, --color-on-brand). */
export const NAV_COLOURS = Object.freeze({ text: '#1c2026', onDark: '#ffffff' });

/** The two ways the header can look on a colour; light (dark text) is tried first. */
export const NAV_SCHEMES = Object.freeze(['light', 'dark']);

function pairs(scheme, brand) {
    if (scheme === 'dark') {
        const white = parseHex(NAV_COLOURS.onDark);
        return [
            ['the site name and menu links', white, TEXT],
            ['the focus ring', white, UI],
        ];
    }
    const strong = mix(parseHex(brand), [0, 0, 0], 0.75);
    return [
        ['the site name and menu links', parseHex(NAV_COLOURS.text), TEXT],
        ['the current page and hovered links', strong, TEXT],
        ['the focus ring', strong, UI],
        ['the built-in logo mark', strong, UI],
    ];
}

/** Every check of one scheme ("light" or "dark") for this background and brand (both #rrggbb). */
export function navBackgroundChecks(background, brand, scheme) {
    return checkPairs(pairs(scheme, brand), background);
}

/** The worst failing check of each scheme: { light, dark }, null where a scheme passes. */
export function worstNavBackgroundChecks(background, brand) {
    return Object.fromEntries(NAV_SCHEMES.map((scheme) => [scheme, worstCheck(navBackgroundChecks(background, brand, scheme))]));
}

/**
 * The scheme the header uses on this colour: "light" (dark text) if it passes,
 * else "dark" (white text) if that passes, else null (the colour can't be used).
 */
export function navScheme(value, brand) {
    if (typeof value !== 'string' || !HEX_PATTERN.test(value) || typeof brand !== 'string' || !HEX_PATTERN.test(brand)) return null;
    const worst = worstNavBackgroundChecks(value, brand);
    return NAV_SCHEMES.find((scheme) => worst[scheme] === null) ?? null;
}
