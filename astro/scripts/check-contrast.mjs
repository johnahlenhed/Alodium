// WCAG contrast for every text and UI colour pair the design uses, read from
// the variables in src/styles/blocks.css (color-mix() is evaluated here the way
// the browser does it). Runs for the default brand and two other colours, each
// with both button styles, then for a sample of other brand colours theme.js
// accepts. Exits with 1 if any pair is below its threshold.
//
//   node scripts/check-contrast.mjs            (from astro/)
//   node scripts/check-contrast.mjs --quiet    (failures and summary only)

import { readFileSync } from 'node:fs';
import { contrast, HEX_PATTERN, mix, parseHex, toHex } from '../src/lib/color.js';
import { FOOTER_COLOURS, footerScheme } from '../src/lib/footerBackground.js';
import { NAV_COLOURS, navScheme } from '../src/lib/navBackground.js';
import { isValidPageBackground, PAGE_COLOURS } from '../src/lib/pageBackground.js';
import { isValidBrand } from '../src/lib/theme.js';

const quiet = process.argv.includes('--quiet');
const css = readFileSync(new URL('../src/styles/blocks.css', import.meta.url), 'utf8');

// --- read the colour variables from the :where(:root) block -----------------

const rootBlock = css.replace(/\/\*[\s\S]*?\*\//g, '').match(/:where\(:root\)\s*\{([^}]*)\}/)?.[1];
if (!rootBlock) throw new Error('No :where(:root) block found in blocks.css');

const declarations = Object.fromEntries(
    [...rootBlock.matchAll(/(--color-[\w-]+)\s*:\s*([^;]+);/g)].map(([, name, value]) => [name, value.trim()]),
);

/**
 * A colour value as { rgb, alpha }. Understands what blocks.css uses: #rrggbb,
 * #000, transparent, var(--x) and color-mix(in srgb, A p%, B).
 */
function evaluate(value, vars, seen = []) {
    value = value.trim();
    if (HEX_PATTERN.test(value)) return { rgb: parseHex(value), alpha: 1 };
    if (/^#[0-9a-fA-F]{3}$/.test(value)) return { rgb: parseHex(`#${[...value.slice(1)].map((c) => c + c).join('')}`), alpha: 1 };
    if (value === 'transparent') return { rgb: [0, 0, 0], alpha: 0 };

    const ref = value.match(/^var\((--[\w-]+)\)$/);
    if (ref) {
        if (seen.includes(ref[1])) throw new Error(`Circular variable ${ref[1]}`);
        if (!(ref[1] in vars)) throw new Error(`Unknown variable ${ref[1]}`);
        return evaluate(vars[ref[1]], vars, [...seen, ref[1]]);
    }

    const colorMix = value.match(/^color-mix\(in srgb,\s*(.+?)\s+([\d.]+)%,\s*(.+)\)$/);
    if (colorMix) {
        const weight = Number(colorMix[2]) / 100;
        const a = evaluate(colorMix[1], vars, seen);
        const b = evaluate(colorMix[3], vars, seen);
        // Premultiplied interpolation, as in CSS (matters when one side is transparent).
        const alpha = a.alpha * weight + b.alpha * (1 - weight);
        const rgb = alpha === 0 ? [0, 0, 0] : a.rgb.map((c, i) => (c * a.alpha * weight + b.rgb[i] * b.alpha * (1 - weight)) / alpha);
        return { rgb, alpha };
    }

    throw new Error(`Can't evaluate colour "${value}"`);
}

/** Every --color-* variable as { rgb, alpha }. Translucent ones are only used through { over }. */
function palette(brand) {
    const vars = { ...declarations, '--color-brand': brand };
    return Object.fromEntries(Object.keys(vars).map((name) => [name, evaluate(vars[name], vars)]));
}

// --- the pairs ----------------------------------------------------------------

const TEXT = 4.5; // WCAG 1.4.3, normal text
const UI = 3; // WCAG 1.4.11, focus rings, borders that identify a control

/**
 * [label, foreground, background, minimum]. Colours are variable names, or
 * { over: name, pixel } for a translucent colour drawn over a photo pixel
 * (the hero overlay over pure white is the worst case for white text).
 */
function pairs(buttons) {
    const overWhite = { over: '--color-hero-overlay', pixel: [255, 255, 255] };
    // The dark hero's overlay under white text, and the light hero's under dark text: the worst photo pixel for each.
    const darkOverWhite = { over: '--color-hero-overlay-dark', pixel: [255, 255, 255] };
    const lightOverBlack = { over: '--color-hero-overlay-light', pixel: [0, 0, 0] };
    const common = [
        ['Body text on page', '--color-text', '--color-bg', 12],
        ['Body text on surface (code, footer)', '--color-text', '--color-surface', TEXT],
        ['Muted text on page', '--color-text-muted', '--color-bg', TEXT],
        ['Muted text on surface (footer)', '--color-text-muted', '--color-surface', TEXT],
        ['Link on page', '--color-brand', '--color-bg', TEXT],
        ['Link hover on page', '--color-brand-strong', '--color-bg', TEXT],
        ['Success message', '--color-success', '--color-bg', TEXT],
        ['Error message', '--color-error', '--color-bg', TEXT],
        ['Skip link, selection', '--color-on-brand', '--color-brand', TEXT],
        ['Form field border', '--color-border-strong', '--color-bg', UI],
        ['Focus ring on page', '--color-focus', '--color-bg', UI],
        ['Focus ring on surface (footer)', '--color-focus', '--color-surface', UI],
        ['Hero text', '--color-on-brand', '--color-brand', TEXT],
        ['Hero text over image (white pixel)', '--color-on-brand', overWhite, TEXT],
        ['Hero focus ring', '--color-on-brand', '--color-brand', UI],
        ['Hero focus ring over image (white pixel)', '--color-on-brand', overWhite, UI],

        // Backgrounds (data.variant, .block--panel and the hero variants).
        ['Light panel: text', '--color-text', '--color-brand-tint', TEXT],
        ['Light panel: muted text (quotes, captions, markers)', '--color-text-muted', '--color-brand-tint', TEXT],
        ['Light panel: link', '--color-brand-strong', '--color-brand-tint', TEXT],
        ['Light panel: link hover', '--color-brand-deep', '--color-brand-tint', TEXT],
        ['Light panel: code (on the page colour)', '--color-text', '--color-bg', TEXT],
        ['Light panel: focus ring', '--color-focus', '--color-brand-tint', UI],
        ['Brand panel: text, links, muted text', '--color-on-brand', '--color-brand', TEXT],
        ['Brand panel: code (on the strong shade)', '--color-on-brand', '--color-brand-strong', TEXT],
        ['Brand panel: focus ring', '--color-on-brand', '--color-brand', UI],
        ['Dark panel: text, links, muted text', '--color-on-brand', '--color-brand-night', TEXT],
        ['Dark panel: code (on the deep shade)', '--color-on-brand', '--color-brand-deep', TEXT],
        ['Dark panel: focus ring', '--color-on-brand', '--color-brand-night', UI],
        ['Selection on brand and dark backgrounds', '--color-brand', '--color-on-brand', TEXT],
        ['Dark hero text', '--color-on-brand', '--color-brand-night', TEXT],
        ['Dark hero text over image (white pixel)', '--color-on-brand', darkOverWhite, TEXT],
        ['Dark hero focus ring over image (white pixel)', '--color-on-brand', darkOverWhite, UI],
        ['Light hero text', '--color-text', '--color-brand-tint', TEXT],
        ['Light hero text over image (black pixel)', '--color-text', lightOverBlack, TEXT],
        ['Light hero focus ring', '--color-brand-strong', '--color-brand-tint', UI],
        // Over an image the light hero's focus ring and outline buttons switch to --color-text (blocks.css).
        ['Light hero focus ring over image (black pixel)', '--color-text', lightOverBlack, UI],
        // No background ("none"): the page colour, also over an image (the text is on a
        // page-colour panel), with the page's focus ring and buttons, so the page pairs.
        ['No-background hero text', '--color-text', '--color-bg', TEXT],
        ['No-background hero focus ring', '--color-focus', '--color-bg', UI],
    ];

    const solid = [
        ['Primary', '--color-on-brand', '--color-brand', TEXT],
        ['Primary edge on page', '--color-brand', '--color-bg', UI],
        ['Primary hover', '--color-on-brand', '--color-brand-strong', TEXT],
        ['Secondary', '--color-brand', '--color-bg', TEXT],
        ['Secondary border', '--color-brand', '--color-bg', UI],
        ['Secondary hover', '--color-brand-strong', '--color-brand-tint', TEXT],
        ['Hero primary (inverted)', '--color-brand', '--color-on-brand', TEXT],
        ['Hero primary edge', '--color-on-brand', '--color-brand', UI],
        ['Hero primary hover', '--color-brand-strong', '--color-brand-tint', TEXT],
        ['Hero secondary (inverted)', '--color-on-brand', '--color-brand', TEXT],
        ['Hero secondary over image', '--color-on-brand', overWhite, TEXT],
        ['Hero secondary border', '--color-on-brand', '--color-brand', UI],
        ['Hero secondary hover', '--color-on-brand', '--color-brand-strong', TEXT],
        // Dark hero: the default hero's inverted buttons on the near-black shade.
        ['Dark hero primary edge', '--color-on-brand', '--color-brand-night', UI],
        ['Dark hero secondary', '--color-on-brand', '--color-brand-night', TEXT],
        ['Dark hero secondary over image', '--color-on-brand', darkOverWhite, TEXT],
        ['Dark hero secondary border', '--color-on-brand', '--color-brand-night', UI],
        // Light hero: the page's buttons, darker where text sits on the tint.
        ['Light hero primary', '--color-on-brand', '--color-brand', TEXT],
        ['Light hero primary edge', '--color-brand', '--color-brand-tint', UI],
        ['Light hero primary border over image', '--color-text', lightOverBlack, UI],
        ['Light hero primary hover', '--color-on-brand', '--color-brand-strong', TEXT],
        ['Light hero secondary', '--color-brand-strong', '--color-brand-tint', TEXT],
        ['Light hero secondary over image', '--color-text', lightOverBlack, TEXT],
        ['Light hero secondary border', '--color-brand-strong', '--color-brand-tint', UI],
        ['Light hero secondary border over image', '--color-text', lightOverBlack, UI],
        ['Light hero secondary hover', '--color-brand-strong', '--color-brand-tint-strong', TEXT],
    ];

    const outline = [
        ['Primary', '--color-brand', '--color-bg', TEXT],
        ['Primary border', '--color-brand', '--color-bg', UI],
        ['Primary hover', '--color-brand-strong', '--color-brand-tint', TEXT],
        ['Secondary (on tint)', '--color-brand-strong', '--color-brand-tint', TEXT],
        ['Secondary hover', '--color-brand-strong', '--color-brand-tint-strong', TEXT],
        ['Hero primary (inverted)', '--color-on-brand', '--color-brand', TEXT],
        ['Hero primary over image', '--color-on-brand', overWhite, TEXT],
        ['Hero primary border', '--color-on-brand', '--color-brand', UI],
        ['Hero primary hover', '--color-on-brand', '--color-brand-strong', TEXT],
        ['Hero secondary (on shade)', '--color-on-brand', '--color-brand-strong', TEXT],
        ['Hero secondary hover', '--color-on-brand', '--color-brand-deep', TEXT],
        ['Dark hero primary', '--color-on-brand', '--color-brand-night', TEXT],
        ['Dark hero primary over image', '--color-on-brand', darkOverWhite, TEXT],
        ['Dark hero primary border', '--color-on-brand', '--color-brand-night', UI],
        ['Dark hero secondary (on shade)', '--color-on-brand', '--color-brand-strong', TEXT],
        ['Light hero primary', '--color-brand-strong', '--color-brand-tint', TEXT],
        ['Light hero primary over image', '--color-text', lightOverBlack, TEXT],
        ['Light hero primary border over image', '--color-text', lightOverBlack, UI],
        ['Light hero primary border', '--color-brand-strong', '--color-brand-tint', UI],
        ['Light hero primary hover', '--color-brand-strong', '--color-brand-tint-strong', TEXT],
        ['Light hero secondary (on the page colour)', '--color-brand-strong', '--color-bg', TEXT],
        ['Light hero secondary hover', '--color-brand-strong', '--color-brand-tint-strong', TEXT],
    ];

    // The contact form button uses the primary colours, so it is covered by "Primary".
    return [...common, ...(buttons === 'outline' ? outline : solid).map(([label, ...rest]) => [`Button (${buttons}): ${label}`, ...rest])];
}

function resolve(colour, colours) {
    if (typeof colour === 'string') return colours[colour].rgb;
    const { rgb, alpha } = colours[colour.over];
    return mix(rgb, colour.pixel, alpha);
}

/** Checks one brand and button style; returns the failures. */
function check(brand, buttons, print) {
    const colours = palette(brand);
    const failures = [];
    if (print) console.log(`\n${brand}, buttons: ${buttons}`);

    for (const [label, fg, bg, min] of pairs(buttons)) {
        const ratio = contrast(resolve(fg, colours), resolve(bg, colours));
        const ok = ratio >= min;
        if (!ok) failures.push(`${brand} ${buttons}: ${label} ${ratio.toFixed(2)} < ${min}`);
        if (print) console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${ratio.toFixed(2).padStart(5)} (min ${min})  ${label}`);
    }
    return failures;
}

// --- run ----------------------------------------------------------------------

const failures = [];
for (const brand of [declarations['--color-brand'], '#7A1F3D', '#0B5D3B']) {
    for (const buttons of ['solid', 'outline']) failures.push(...check(brand, buttons, !quiet));
}

// Any brand colour theme.js accepts: a fixed-seed sample plus colours just
// above the 4.5:1 limit, where the derived colours have the least room.
let seed = 42;
const random = () => ((seed = (seed * 1103515245 + 12345) % 2 ** 31) / 2 ** 31);
const sample = [];
while (sample.length < 3000) {
    const hex = toHex([random() * 256, random() * 256, random() * 256].map(Math.floor));
    if (isValidBrand(hex)) sample.push(hex);
}
const nearLimit = sample.filter((hex) => contrast(parseHex(hex), [255, 255, 255]) < 4.7);
const sweepFailures = [];
for (const brand of sample) {
    for (const buttons of ['solid', 'outline']) sweepFailures.push(...check(brand, buttons, false));
}
console.log(`\nSample: ${sample.length} accepted brand colours (${nearLimit.length} between 4.5 and 4.7:1), both button styles: ${sweepFailures.length} failing pairs.`);
failures.push(...sweepFailures.slice(0, 20));

// --- page background colours (pages.background_color, .page-background) -------
//
// The client picks any colour src/lib/pageBackground.js accepts. Its fixed
// colours must be the ones in blocks.css, and on every colour it accepts, the
// colours blocks.css puts on a .page-background must meet their thresholds.

for (const [key, name] of Object.entries({ text: '--color-text', textMuted: '--color-text-muted', borderStrong: '--color-border-strong', success: '--color-success', error: '--color-error' })) {
    if (PAGE_COLOURS[key].toLowerCase() !== declarations[name].toLowerCase()) {
        failures.push(`pageBackground.js ${key} is ${PAGE_COLOURS[key]}, blocks.css ${name} is ${declarations[name]}`);
    }
}

/** [label, colour on .page-background, minimum]; the background is --page-bg. */
const PAGE_PAIRS = [
    ['text, headings', '--color-text', TEXT],
    ['muted text', '--color-text-muted', TEXT],
    ['links (--color-link), secondary and outline button text', '--color-brand-strong', TEXT],
    ['link hover', '--color-brand-deep', TEXT],
    ['contact form success', '--color-success', TEXT],
    ['contact form errors', '--color-error', TEXT],
    ['focus ring (--color-focus)', '--color-brand-strong', UI],
    ['form field borders', '--color-border-strong', UI],
    ['solid primary button edge', '--color-brand', UI],
    ['secondary and outline button borders', '--color-brand-strong', UI],
];

const pageBrands = [declarations['--color-brand'], '#7A1F3D', '#0B5D3B', ...sample.filter((_, i) => i % 10 === 0), ...nearLimit];
let pageChecked = 0;
const pageFailures = [];
for (const brand of pageBrands) {
    const colours = palette(brand);
    for (let i = 0; i < 40; i++) {
        // Light colours, where the rule has to decide; it accepts some and refuses others.
        const background = toHex([random(), random(), random()].map((c) => 200 + Math.floor(c * 56)));
        if (!isValidPageBackground(background, brand)) continue;
        pageChecked++;
        const bg = parseHex(background);
        for (const [label, fg, min] of PAGE_PAIRS) {
            const ratio = contrast(colours[fg].rgb, bg);
            if (ratio < min) pageFailures.push(`${brand} on page ${background}: ${label} ${ratio.toFixed(2)} < ${min}`);
        }
    }
}
console.log(`Page backgrounds: ${pageChecked} accepted colours on ${pageBrands.length} brand colours: ${pageFailures.length} failing pairs.`);
failures.push(...pageFailures.slice(0, 20));

// --- header background colours (site_settings.nav_background_color) ------------
//
// src/lib/navBackground.js picks dark text ("light") or white text ("dark") for
// a colour, or refuses it. Its fixed colours must be the ones in blocks.css,
// and on every colour it accepts, the colours site.css puts on the header in
// that scheme (.site-header--light, --dark) must meet their thresholds.

for (const [key, name] of Object.entries({ text: '--color-text', onDark: '--color-on-brand' })) {
    if (NAV_COLOURS[key].toLowerCase() !== declarations[name].toLowerCase()) {
        failures.push(`navBackground.js ${key} is ${NAV_COLOURS[key]}, blocks.css ${name} is ${declarations[name]}`);
    }
}

/** [label, colour on the header (--nav-text, --nav-accent, --color-focus), minimum] per scheme. */
const NAV_PAIRS = {
    light: [
        ['site name and menu links (--nav-text)', '--color-text', TEXT],
        ['current page and hover (--nav-accent)', '--color-brand-strong', TEXT],
        ['focus ring', '--color-brand-strong', UI],
        ['built-in mark (--nav-accent)', '--color-brand-strong', UI],
    ],
    dark: [
        ['site name, menu links, current page, hover, mark', '--color-on-brand', TEXT],
        ['focus ring', '--color-on-brand', UI],
    ],
};

const navCounts = { light: 0, dark: 0, refused: 0 };
const navFailures = [];
for (const brand of pageBrands) {
    const colours = palette(brand);
    // Any colour, plus the brand itself and its derived shades (common header choices).
    const candidates = [brand, toHex(colours['--color-brand-strong'].rgb), toHex(colours['--color-brand-night'].rgb)];
    for (let i = 0; i < 30; i++) candidates.push(toHex([random(), random(), random()].map((c) => Math.floor(c * 256))));
    for (const background of candidates) {
        const scheme = navScheme(background, brand);
        if (scheme === null) {
            navCounts.refused++;
            continue;
        }
        navCounts[scheme]++;
        const bg = parseHex(background);
        for (const [label, fg, min] of NAV_PAIRS[scheme]) {
            const ratio = contrast(colours[fg].rgb, bg);
            if (ratio < min) navFailures.push(`${brand} header ${background} (${scheme}): ${label} ${ratio.toFixed(2)} < ${min}`);
        }
    }
}
console.log(`Header backgrounds on ${pageBrands.length} brand colours: ${navCounts.light} with dark text, ${navCounts.dark} with white text (${navCounts.refused} refused): ${navFailures.length} failing pairs.`);
failures.push(...navFailures.slice(0, 20));

// --- footer background colours (site_settings.footer_background_color) ---------
//
// The same for the footer (src/lib/footerBackground.js, .site-footer--light and
// --dark in site.css): it has muted text (--footer-muted) and no mark.

for (const [key, name] of Object.entries({ text: '--color-text', textMuted: '--color-text-muted', onDark: '--color-on-brand' })) {
    if (FOOTER_COLOURS[key].toLowerCase() !== declarations[name].toLowerCase()) {
        failures.push(`footerBackground.js ${key} is ${FOOTER_COLOURS[key]}, blocks.css ${name} is ${declarations[name]}`);
    }
}

const FOOTER_PAIRS = {
    light: [
        ['site name and menu links (--nav-text)', '--color-text', TEXT],
        ['footer text and analytics note (--footer-muted)', '--color-text-muted', TEXT],
        ['current page and hover (--nav-accent)', '--color-brand-strong', TEXT],
        ['focus ring', '--color-brand-strong', UI],
    ],
    dark: [
        ['site name, links, footer text, note, current page, hover', '--color-on-brand', TEXT],
        ['focus ring', '--color-on-brand', UI],
    ],
};

const footerCounts = { light: 0, dark: 0, refused: 0 };
const footerFailures = [];
for (const brand of pageBrands) {
    const colours = palette(brand);
    const candidates = [brand, toHex(colours['--color-brand-night'].rgb), declarations['--color-surface']];
    for (let i = 0; i < 30; i++) candidates.push(toHex([random(), random(), random()].map((c) => Math.floor(c * 256))));
    for (const background of candidates) {
        const scheme = footerScheme(background, brand);
        if (scheme === null) {
            footerCounts.refused++;
            continue;
        }
        footerCounts[scheme]++;
        const bg = parseHex(background);
        for (const [label, fg, min] of FOOTER_PAIRS[scheme]) {
            const ratio = contrast(colours[fg].rgb, bg);
            if (ratio < min) footerFailures.push(`${brand} footer ${background} (${scheme}): ${label} ${ratio.toFixed(2)} < ${min}`);
        }
    }
}
console.log(`Footer backgrounds on ${pageBrands.length} brand colours: ${footerCounts.light} with dark text, ${footerCounts.dark} with white text (${footerCounts.refused} refused): ${footerFailures.length} failing pairs.`);
failures.push(...footerFailures.slice(0, 20));

if (failures.length) {
    console.error(`\n${failures.length} pair(s) below the threshold:\n  ${failures.join('\n  ')}`);
    process.exit(1);
}
console.log('All pairs meet their thresholds.');
