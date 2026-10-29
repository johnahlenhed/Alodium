// Run with: node resources/js/theme.check.mjs
// Plain Node checks for the admin colour tokens (resources/css/theme.css): the
// WCAG AA contrast of the pairs the admin uses, and that no class names a
// colour the theme doesn't define (Tailwind would silently leave it out).
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { contrast, parseHex, toHex } from '../../astro/src/lib/color.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
// Through Tailwind's own loader: the config's imports don't resolve in plain Node.
const config = createRequire(import.meta.url)('tailwindcss/loadConfig')(root + 'tailwind.config.js');
const read = (path) => readFileSync(root + path, 'utf8');
const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const SCALES = ['brand', 'gray', 'success', 'warning', 'danger'];
const AA_TEXT = 4.5;
const AA_NON_TEXT = 3;

let checks = 0;
const check = (name, fn) => { fn(); checks++; console.log('ok -', name); };

// --color-x: R G B; /* #HEX */   or   --color-x: var(--color-y);
const css = read('resources/css/theme.css');
const declared = {};
for (const [, name, value, hex] of css.matchAll(/--color-([\w-]+):\s*([^;]+);(?:\s*\/\*\s*(#[0-9A-Fa-f]{6})?)?/g)) {
    declared[name] = { value: value.trim(), hex };
}
const tokens = {};
const resolve = (name, seen = []) => {
    assert.ok(declared[name], `--color-${name} is not defined in theme.css`);
    assert.ok(!seen.includes(name), `--color-${name} refers to itself`);
    const alias = declared[name].value.match(/^var\(--color-([\w-]+)\)$/);
    if (alias) return resolve(alias[1], [...seen, name]);
    const rgb = declared[name].value.split(/\s+/).map(Number);
    assert.ok(rgb.length === 3 && rgb.every((c) => Number.isInteger(c) && c >= 0 && c <= 255), `--color-${name}: "${declared[name].value}" is not "R G B"`);
    return rgb;
};
for (const name of Object.keys(declared)) tokens[name] = resolve(name);

const ratio = (a, b) => contrast(tokens[a], tokens[b]);
const fmt = (n) => n.toFixed(2);
const assertPair = (fg, bg, min) => {
    const r = ratio(fg, bg);
    assert.ok(r >= min, `${fg} on ${bg} is ${fmt(r)}:1, needs ${min}:1`);
};
tokens.white = [255, 255, 255];

check('every channel value matches the hex in its comment', () => {
    for (const [name, { value, hex }] of Object.entries(declared)) {
        if (hex) assert.equal(toHex(tokens[name]), hex.toLowerCase(), `--color-${name}: ${value} is not ${hex}`);
    }
});

check('the brand colour is brand-800 and the primary colour', () => {
    assert.deepEqual(tokens['brand-800'], parseHex('#1B2A4A'));
    assert.deepEqual(tokens.primary, parseHex('#1B2A4A'));
});

check('every scale has all eleven steps, light to dark', () => {
    for (const scale of SCALES) {
        const steps = STEPS.map((step) => tokens[`${scale}-${step}`]);
        steps.forEach((rgb, i) => assert.ok(rgb, `${scale}-${STEPS[i]} is missing`));
        for (let i = 1; i < steps.length; i++) {
            assert.ok(contrast(steps[i], tokens.white) > contrast(steps[i - 1], tokens.white), `${scale}-${STEPS[i]} is not darker than ${scale}-${STEPS[i - 1]}`);
        }
    }
});

check('steps 500 and darker are AA text on the surface, gray-50 and the canvas', () => {
    for (const scale of SCALES) {
        for (const step of STEPS.filter((s) => s >= 500)) {
            for (const bg of ['surface', 'gray-50', 'canvas']) assertPair(`${scale}-${step}`, bg, AA_TEXT);
        }
    }
});

check('steps 600 and darker are AA text on their own tints (50, 100, 200)', () => {
    for (const scale of SCALES) {
        for (const step of STEPS.filter((s) => s >= 600)) {
            for (const tint of [50, 100, 200]) assertPair(`${scale}-${step}`, `${scale}-${tint}`, AA_TEXT);
        }
    }
});

check('step 400 is AA for icons and other non-text UI (3:1) on the surface', () => {
    for (const scale of SCALES) assertPair(`${scale}-400`, 'surface', AA_NON_TEXT);
});

check('roles: text on primary and danger fills, and the focus ring', () => {
    for (const bg of ['primary', 'primary-hover', 'primary-active']) assertPair('on-primary', bg, AA_TEXT);
    // DangerButton: white text on danger-600, -500 on hover, -700 when pressed.
    for (const bg of ['danger-500', 'danger-600', 'danger-700']) assertPair('white', bg, AA_TEXT);
    // Focus rings (ring-brand-500) next to the surface and the canvas.
    for (const bg of ['surface', 'canvas']) assertPair('brand-500', bg, AA_NON_TEXT);
});

// Class names in the admin's source.
const sources = [];
const walk = (dir) => {
    for (const entry of readdirSync(root + dir, { withFileTypes: true })) {
        const path = `${dir}/${entry.name}`;
        if (entry.isDirectory()) walk(path);
        else if (/\.(jsx|js)$/.test(entry.name)) sources.push(path);
    }
};
walk('resources/js');
sources.push('resources/css/app.css');

// Class colour name -> token name, read from tailwind.config.js.
const classColours = {};
for (const [name, value] of Object.entries(config.theme.colors)) {
    const add = (key, v) => {
        const token = typeof v === 'string' && v.match(/--color-([\w-]+)\)/)?.[1];
        if (token) classColours[key] = token;
    };
    if (typeof value === 'string') add(name, value);
    else for (const [step, v] of Object.entries(value)) add(step === 'DEFAULT' ? name : `${name}-${step}`, v);
}
classColours.white = 'white';

const PALETTE = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|brand|success|warning|danger';
const UTILITY = 'bg|text|border(?:-[xytrblse])?|ring|ring-offset|outline|divide|from|via|to|fill|stroke|placeholder|accent|caret|decoration|shadow';

check('no class uses a colour the theme does not define (or the forms-only blue)', () => {
    const problems = [];
    for (const path of sources) {
        for (const [match, , colour] of read(path).matchAll(new RegExp(`\\b(?:${UTILITY})-((${PALETTE})-\\d{2,3})\\b`, 'g'))) {
            if (colour === 'blue' || !classColours[match.replace(new RegExp(`^(?:${UTILITY})-`), '')]) problems.push(`${path}: ${match}`);
        }
    }
    assert.deepEqual(problems, []);
});

check('no hex colours in the admin source, only tokens', () => {
    // These two show the public site's brand colour (a site setting), not the admin's.
    const allowed = ['resources/js/Pages/Settings/Partials/ThemePreview.jsx', 'resources/js/Pages/Settings/Partials/BrandColorField.jsx'];
    const problems = sources
        .filter((path) => !allowed.includes(path))
        // Not \b: in arbitrary values (#fff_0%) "_" counts as a word character.
        .flatMap((path) => [...read(path).matchAll(/#[0-9A-Fa-f]{3,8}(?![0-9A-Za-z])/g)].map((m) => `${path}: ${m[0]}`));
    assert.deepEqual(problems, []);
});

check('text and background set on the same element are AA (4.5:1)', () => {
    // A heuristic: unprefixed text-* and bg-* classes in one string literal.
    // ${...} parts of template literals are left out (they are separate strings).
    const problems = [];
    let pairs = 0;
    for (const path of sources.filter((p) => p.endsWith('.jsx') || p.endsWith('.js'))) {
        const code = read(path);
        const literals = [
            ...[...code.matchAll(/'([^'\n]*)'|"([^"\n]*)"/g)].map((m) => m[1] ?? m[2]),
            ...[...code.matchAll(/`([^`]*)`/g)].map((m) => m[1].replace(/\$\{[^}]*\}/g, ' ')),
        ];
        for (const literal of literals) {
            const classes = literal.split(/\s+/);
            const colour = (prefix) => classes.filter((c) => c.startsWith(prefix)).map((c) => classColours[c.slice(prefix.length)]).filter(Boolean);
            for (const fg of colour('text-')) {
                for (const bg of colour('bg-')) {
                    pairs++;
                    const r = ratio(fg, bg);
                    if (r < AA_TEXT) problems.push(`${path}: text-${fg} on bg-${bg} is ${fmt(r)}:1`);
                }
            }
        }
    }
    assert.ok(pairs > 10, `only ${pairs} pairs found: the scan is broken`);
    assert.deepEqual(problems, []);
});

console.log(`\n${checks} checks passed`);
