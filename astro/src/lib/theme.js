// Turns the client's site settings into the theme BaseLayout applies: CSS
// variables, the data-button attribute on <html>, and the fonts to load.
// Every value is checked against an allow-list, and anything else silently
// falls back to the default, so nothing from the settings is ever written into
// CSS unchecked. The only free-form value, the brand colour, must be a 6-digit
// hex colour; every other CSS value comes from the tables below.
//
// Until the site settings feature exists, BaseLayout calls buildTheme() with
// no settings, which gives the defaults. The defaults must match the variables
// in src/styles/blocks.css (scripts/check-theme.mjs checks that they do).

import { contrast, HEX_PATTERN, parseHex } from './color.js';
import { FONTS } from './fonts.js';

export const DEFAULT_SETTINGS = Object.freeze({
    brand: '#1B2A4A',
    fontBody: 'inter',
    fontHeading: 'inter',
    corners: 'soft',
    buttons: 'solid',
});

export const CORNERS = Object.freeze({ sharp: '0px', soft: '6px', round: '14px' });
export const BUTTON_STYLES = Object.freeze({ solid: 'solid', outline: 'outline' });

/** White text on the brand colour (buttons, hero) needs at least this contrast. */
export const MIN_BRAND_CONTRAST = 4.5;

const WHITE = [255, 255, 255];

/** A 6-digit hex colour with at least 4.5:1 contrast against white. */
export function isValidBrand(value) {
    return typeof value === 'string' && HEX_PATTERN.test(value) && contrast(parseHex(value), WHITE) >= MIN_BRAND_CONTRAST;
}

// Object.hasOwn, not `value in table`: "constructor" or "__proto__" must not
// match something inherited from Object.prototype.
const allowed = (table, value) => typeof value === 'string' && Object.hasOwn(table, value);

/**
 * The settings with every invalid or missing value replaced by its default.
 * Each value is read exactly once, so a getter can't return one value for the
 * check and another for the CSS.
 */
export function resolveSettings(settings) {
    const source = settings !== null && typeof settings === 'object' && !Array.isArray(settings) ? settings : {};
    const read = (key) => (Object.hasOwn(source, key) ? source[key] : undefined);

    const brand = read('brand');
    const fontBody = read('fontBody');
    const fontHeading = read('fontHeading');
    const corners = read('corners');
    const buttons = read('buttons');

    return {
        brand: (isValidBrand(brand) ? brand : DEFAULT_SETTINGS.brand).toLowerCase(),
        fontBody: allowed(FONTS, fontBody) ? fontBody : DEFAULT_SETTINGS.fontBody,
        fontHeading: allowed(FONTS, fontHeading) ? fontHeading : DEFAULT_SETTINGS.fontHeading,
        corners: allowed(CORNERS, corners) ? corners : DEFAULT_SETTINGS.corners,
        buttons: allowed(BUTTON_STYLES, buttons) ? buttons : DEFAULT_SETTINGS.buttons,
    };
}

/**
 * { brand, fontBody, fontHeading, corners, buttons } ->
 *   css:            a :root rule with the client-controlled variables
 *   htmlAttributes: attributes for <html> (data-button)
 *   fontsToLoad:    FONTS entries with a file, each once, for @font-face and preload
 */
export function buildTheme(settings) {
    const theme = resolveSettings(settings);

    const css =
        `:root{--color-brand:${theme.brand};` +
        `--font-body:${FONTS[theme.fontBody].stack};` +
        `--font-heading:${FONTS[theme.fontHeading].stack};` +
        `--radius:${CORNERS[theme.corners]}}`;

    // A Set, so body and headings in the same font load its file once.
    const fontsToLoad = [...new Set([theme.fontBody, theme.fontHeading])].map((key) => FONTS[key]).filter((font) => font.file);

    return { css, htmlAttributes: { 'data-button': BUTTON_STYLES[theme.buttons] }, fontsToLoad };
}
