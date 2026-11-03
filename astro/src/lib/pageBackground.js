// Which background colours a page can have (pages.background_color): any
// #rrggbb colour on which everything that sits straight on the page stays
// readable. On a page with a background, links, button text and the focus ring
// use the darker brand shades, as the light panels do (.page-background in
// src/styles/blocks.css). The page's text is dark, so only light colours pass.
//
// The same rule is used by the build (BaseLayout), the admin's live readout
// (Pages/Partials/PageBackgroundField.jsx) and the server (App\Support\
// PageBackground, kept equal by tests/Fixtures/page-backgrounds.json).
// scripts/check-contrast.mjs checks the colours below against blocks.css.

import { HEX_PATTERN, mix, parseHex } from './color.js';
import { checkPairs, worstCheck } from './contrastChecks.js';

/** The fixed colours that sit on a page background, as in blocks.css. */
export const PAGE_COLOURS = Object.freeze({
    text: '#1c2026',
    textMuted: '#59606c',
    borderStrong: '#7c8494',
    success: '#1d7a3e',
    error: '#b3261e',
});

export const TEXT = 4.5; // WCAG 1.4.3, normal text
export const UI = 3; // WCAG 1.4.11, focus rings and control borders
const BLACK = [0, 0, 0];

/**
 * [what, colour, minimum] for each colour on the background, for this brand.
 * --color-brand-strong and --color-brand-deep are mixed as in blocks.css.
 */
function pairs(brand) {
    const rgb = parseHex(brand);
    const strong = mix(rgb, BLACK, 0.75);
    const deep = mix(rgb, BLACK, 0.55);
    return [
        ['the text', parseHex(PAGE_COLOURS.text), TEXT],
        ['muted text (dates, captions)', parseHex(PAGE_COLOURS.textMuted), TEXT],
        ['links and button text', strong, TEXT],
        ['links on hover', deep, TEXT],
        ["the contact form's success message", parseHex(PAGE_COLOURS.success), TEXT],
        ["the contact form's error messages", parseHex(PAGE_COLOURS.error), TEXT],
        ['the focus ring', strong, UI],
        ['form field borders', parseHex(PAGE_COLOURS.borderStrong), UI],
        ['button edges', rgb, UI],
    ];
}

/**
 * Every check for this background and brand (both #rrggbb), in a fixed order:
 * [{ what, ratio, minimum, ok }].
 */
export function pageBackgroundChecks(background, brand) {
    return checkPairs(pairs(brand), background);
}

/**
 * The check furthest below its minimum (the lowest ratio / minimum; the first
 * of equals), or null when all pass. Both colours must be #rrggbb.
 */
export function worstPageBackgroundCheck(background, brand) {
    return worstCheck(pageBackgroundChecks(background, brand));
}

/** A #rrggbb colour that every check passes on, for this (valid) brand colour. */
export function isValidPageBackground(value, brand) {
    return typeof value === 'string' && HEX_PATTERN.test(value) && HEX_PATTERN.test(brand) && worstPageBackgroundCheck(value, brand) === null;
}
