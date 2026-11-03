// Which background colours the site footer can have (site_settings.
// footer_background_color), and with which text, as for the header
// (navBackground.js): dark text on a light colour, or white text on a dark
// one; a colour must pass every check of one of the two. The footer has what
// the header has, minus the built-in mark, plus muted text (the footer text and
// the analytics note). With dark text, the current page, hovered links and the
// focus ring use the darker brand shade; with white text, everything is white.
//
// Used by the build (SiteFooter), the Settings page's live readout and,
// mirrored, the server (App\Support\FooterBackground, kept equal by
// tests/Fixtures/footer-backgrounds.json). scripts/check-contrast.mjs checks
// the colours below against blocks.css.

import { HEX_PATTERN, mix, parseHex } from './color.js';
import { checkPairs, worstCheck } from './contrastChecks.js';
import { TEXT, UI } from './pageBackground.js';

/** The fixed colours in the footer, as in blocks.css (--color-text, --color-text-muted, --color-on-brand). */
export const FOOTER_COLOURS = Object.freeze({ text: '#1c2026', textMuted: '#59606c', onDark: '#ffffff' });

/** The two ways the footer can look on a colour; light (dark text) is tried first. */
export const FOOTER_SCHEMES = Object.freeze(['light', 'dark']);

function pairs(scheme, brand) {
    if (scheme === 'dark') {
        const white = parseHex(FOOTER_COLOURS.onDark);
        return [
            ['the site name, menu links and footer text', white, TEXT],
            ['the focus ring', white, UI],
        ];
    }
    const strong = mix(parseHex(brand), [0, 0, 0], 0.75);
    return [
        ['the site name and menu links', parseHex(FOOTER_COLOURS.text), TEXT],
        ['the footer text and the analytics note', parseHex(FOOTER_COLOURS.textMuted), TEXT],
        ['the current page and hovered links', strong, TEXT],
        ['the focus ring', strong, UI],
    ];
}

/** Every check of one scheme ("light" or "dark") for this background and brand (both #rrggbb). */
export function footerBackgroundChecks(background, brand, scheme) {
    return checkPairs(pairs(scheme, brand), background);
}

/** The worst failing check of each scheme: { light, dark }, null where a scheme passes. */
export function worstFooterBackgroundChecks(background, brand) {
    return Object.fromEntries(FOOTER_SCHEMES.map((scheme) => [scheme, worstCheck(footerBackgroundChecks(background, brand, scheme))]));
}

/**
 * The scheme the footer uses on this colour: "light" (dark text) if it passes,
 * else "dark" (white text) if that passes, else null (the colour can't be used).
 */
export function footerScheme(value, brand) {
    if (typeof value !== 'string' || !HEX_PATTERN.test(value) || typeof brand !== 'string' || !HEX_PATTERN.test(brand)) return null;
    const worst = worstFooterBackgroundChecks(value, brand);
    return FOOTER_SCHEMES.find((scheme) => worst[scheme] === null) ?? null;
}
