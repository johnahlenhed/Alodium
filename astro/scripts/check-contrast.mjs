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

if (failures.length) {
    console.error(`\n${failures.length} pair(s) below the threshold:\n  ${failures.join('\n  ')}`);
    process.exit(1);
}
console.log('All pairs meet their thresholds.');
