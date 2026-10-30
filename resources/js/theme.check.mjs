// Run with: node resources/js/theme.check.mjs
// Plain Node checks for the admin colour tokens (resources/css/theme.css), in
// the light and the dark theme: the WCAG AA contrast of the pairs the admin
// uses, that no class names a colour the theme doesn't define (Tailwind would
// silently leave it out), and that the first-paint script in app.blade.php
// picks the same theme as resources/js/utils/theme.js.
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { contrast, parseHex, toHex } from '../../astro/src/lib/color.js';
import { parsePreference, resolveTheme, STORAGE_KEY } from './utils/theme.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
// Through Tailwind's own loader: the config's imports don't resolve in plain Node.
const config = createRequire(import.meta.url)('tailwindcss/loadConfig')(root + 'tailwind.config.js');
const read = (path) => readFileSync(root + path, 'utf8');
const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const SCALES = ['brand', 'gray', 'success', 'warning', 'danger'];
const THEMES = ['light', 'dark'];
const AA_TEXT = 4.5;
const AA_NON_TEXT = 3;

let checks = 0;
const check = (name, fn) => { fn(); checks++; console.log('ok -', name); };

// The rule blocks of theme.css, in order: { selectors, declared: { name: { value, hex } } }.
// A declaration is "--color-x: R G B; /* #HEX */" or "--color-x: var(--color-y);".
const css = read('resources/css/theme.css');
const blocks = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map(([, selector, body]) => ({
    selectors: selector.replace(/\/\*[\s\S]*?\*\//g, '').split(',').map((s) => s.trim()),
    declared: Object.fromEntries(
        [...body.matchAll(/--color-([\w-]+):\s*([^;]+);(?:[ \t]*\/\*\s*(#[0-9A-Fa-f]{6})?)?/g)].map(([, name, value, hex]) => [name, { value: value.trim(), hex }]),
    ),
}));
const LIGHT = "[data-theme='light']";
const DARK = "[data-theme='dark']";
// <html data-theme="dark"> also matches :root: the dark block overrides the :root ones (same specificity, later).
const appliesTo = (theme, block) => block.selectors.includes(':root') || block.selectors.includes(theme === 'dark' ? DARK : LIGHT);
const declaredIn = (theme) => Object.assign({}, ...blocks.filter((block) => appliesTo(theme, block)).map((block) => block.declared));

const resolveAll = (declared) => {
    const resolve = (name, seen = []) => {
        assert.ok(declared[name], `--color-${name} is not defined in theme.css`);
        assert.ok(!seen.includes(name), `--color-${name} refers to itself`);
        const alias = declared[name].value.match(/^var\(--color-([\w-]+)\)$/);
        if (alias) return resolve(alias[1], [...seen, name]);
        const rgb = declared[name].value.split(/\s+/).map(Number);
        assert.ok(rgb.length === 3 && rgb.every((c) => Number.isInteger(c) && c >= 0 && c <= 255), `--color-${name}: "${declared[name].value}" is not "R G B"`);
        return rgb;
    };
    return { ...Object.fromEntries(Object.keys(declared).map((name) => [name, resolve(name)])), white: [255, 255, 255] };
};
const tokensOf = Object.fromEntries(THEMES.map((theme) => [theme, resolveAll(declaredIn(theme))]));

const fmt = (n) => n.toFixed(2);
const pairChecker = (theme) => {
    const tokens = tokensOf[theme];
    const ratio = (a, b) => contrast(tokens[a], tokens[b]);
    const assertPair = (fg, bg, min) => {
        const r = ratio(fg, bg);
        assert.ok(r >= min, `${theme}: ${fg} on ${bg} is ${fmt(r)}:1, needs ${min}:1`);
    };
    return { tokens, ratio, assertPair };
};

check('every channel value matches the hex in its comment', () => {
    for (const block of blocks) {
        for (const [name, { value, hex }] of Object.entries(block.declared)) {
            if (hex) assert.equal(toHex(value.split(/\s+/).map(Number)), hex.toLowerCase(), `--color-${name}: ${value} is not ${hex}`);
        }
    }
});

check('the dark theme overrides every token the light theme sets', () => {
    const light = blocks.find((block) => block.selectors.includes(LIGHT));
    const dark = blocks.find((block) => block.selectors.includes(DARK));
    assert.ok(light && dark, `theme.css needs a ${LIGHT} and a ${DARK} block`);
    assert.deepEqual(Object.keys(light.declared).filter((name) => !(name in dark.declared)), []);
    assert.deepEqual(Object.keys(dark.declared).filter((name) => !(name in light.declared)), []);
});

check('the brand colour: brand-800 and primary in the light theme, the navigation bar in both', () => {
    assert.deepEqual(tokensOf.light['brand-800'], parseHex('#1B2A4A'));
    assert.deepEqual(tokensOf.light.primary, parseHex('#1B2A4A'));
    for (const theme of THEMES) assert.deepEqual(tokensOf[theme].nav, parseHex('#1B2A4A'), theme);
});

for (const theme of THEMES) {
    const { tokens, assertPair } = pairChecker(theme);

    check(`${theme}: every scale has all eleven steps, each further from the surface`, () => {
        for (const scale of SCALES) {
            const steps = STEPS.map((step) => tokens[`${scale}-${step}`]);
            steps.forEach((rgb, i) => assert.ok(rgb, `${scale}-${STEPS[i]} is missing`));
            for (let i = 1; i < steps.length; i++) {
                assert.ok(
                    contrast(steps[i], tokens.surface) > contrast(steps[i - 1], tokens.surface),
                    `${theme}: ${scale}-${STEPS[i]} is not further from the surface than ${scale}-${STEPS[i - 1]}`,
                );
            }
        }
    });

    check(`${theme}: steps 500 and up are AA text on the surface, gray-50 and the canvas`, () => {
        for (const scale of SCALES) {
            for (const step of STEPS.filter((s) => s >= 500)) {
                for (const bg of ['surface', 'gray-50', 'canvas']) assertPair(`${scale}-${step}`, bg, AA_TEXT);
            }
        }
    });

    check(`${theme}: steps 600 and up are AA text on their own tints (50, 100, 200)`, () => {
        for (const scale of SCALES) {
            for (const step of STEPS.filter((s) => s >= 600)) {
                for (const tint of [50, 100, 200]) assertPair(`${scale}-${step}`, `${scale}-${tint}`, AA_TEXT);
            }
        }
    });

    check(`${theme}: step 400 is AA for icons and other non-text UI (3:1) on the surface`, () => {
        for (const scale of SCALES) assertPair(`${scale}-400`, 'surface', AA_NON_TEXT);
    });

    check(`${theme}: roles: text on the primary and danger fills, and the focus ring`, () => {
        for (const bg of ['primary', 'primary-hover', 'primary-active']) assertPair('on-primary', bg, AA_TEXT);
        for (const bg of ['danger-fill', 'danger-fill-hover', 'danger-fill-active']) assertPair('on-danger', bg, AA_TEXT);
        // Focus rings (ring-brand-500) next to the surface and the canvas.
        for (const bg of ['surface', 'canvas']) assertPair('brand-500', bg, AA_NON_TEXT);
    });

    check(`${theme}: roles: the navigation bar`, () => {
        for (const fg of ['on-nav', 'on-nav-muted']) {
            for (const bg of ['nav', 'nav-hover']) assertPair(fg, bg, AA_TEXT);
        }
        // The current item's underline, and the unread badge (text-nav on bg-on-nav).
        assertPair('nav-accent', 'nav', AA_NON_TEXT);
        assertPair('nav', 'on-nav', AA_TEXT);
    });
}

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
// Shows the public site, always in the light theme (data-theme="light" on its card).
const PUBLIC_PREVIEW = 'resources/js/Pages/Settings/Partials/ThemePreview.jsx';

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
    const allowed = [PUBLIC_PREVIEW, 'resources/js/Pages/Settings/Partials/BrandColorField.jsx'];
    const problems = sources
        .filter((path) => !allowed.includes(path))
        // Not \b: in arbitrary values (#fff_0%) "_" counts as a word character.
        .flatMap((path) => [...read(path).matchAll(/#[0-9A-Fa-f]{3,8}(?![0-9A-Za-z])/g)].map((m) => `${path}: ${m[0]}`));
    assert.deepEqual(problems, []);
});

// Every string literal in the JS source, split into classes. A heuristic:
// ${...} parts of template literals are left out (they are separate strings).
const literals = sources
    .filter((path) => path.endsWith('.jsx') || path.endsWith('.js'))
    .flatMap((path) => {
        const code = read(path);
        return [
            ...[...code.matchAll(/'([^'\n]*)'|"([^"\n]*)"/g)].map((m) => m[1] ?? m[2]),
            ...[...code.matchAll(/`([^`]*)`/g)].map((m) => m[1].replace(/\$\{[^}]*\}/g, ' ')),
        ].map((literal) => ({ path, classes: literal.split(/\s+/) }));
    });
// Colour tokens of the unprefixed classes with this prefix (text-, bg-, border-).
const coloursOf = (classes, prefix) =>
    classes.filter((c) => c.startsWith(prefix)).map((c) => classColours[c.slice(prefix.length)]).filter(Boolean);
const inTheme = (theme) => literals.filter(({ path }) => theme === 'light' || path !== PUBLIC_PREVIEW);

for (const theme of THEMES) {
    const { ratio } = pairChecker(theme);

    check(`${theme}: text and background set on the same element are AA (4.5:1)`, () => {
        const problems = [];
        let pairs = 0;
        for (const { path, classes } of inTheme(theme)) {
            for (const fg of coloursOf(classes, 'text-')) {
                for (const bg of coloursOf(classes, 'bg-')) {
                    pairs++;
                    const r = ratio(fg, bg);
                    if (r < AA_TEXT) problems.push(`${path}: text-${fg} on bg-${bg} is ${fmt(r)}:1`);
                }
            }
        }
        assert.ok(pairs > 10, `only ${pairs} pairs found: the scan is broken`);
        assert.deepEqual(problems, []);
    });

    check(`${theme}: form controls have a 3:1 border on the surface (WCAG 1.4.11)`, () => {
        // A control is recognised by its focus style: text inputs, selects and
        // textareas change their border; checkboxes and radios are text-brand-600
        // with a focus ring. Buttons are left out: their text identifies them.
        const isControl = (classes) =>
            classes.some((c) => /^focus(-within)?:border-brand-500$/.test(c)) ||
            (classes.includes('text-brand-600') && classes.includes('focus:ring-brand-500'));
        const problems = [];
        let controls = 0;
        for (const { path, classes } of inTheme(theme).filter(({ classes }) => isControl(classes))) {
            controls++;
            for (const border of coloursOf(classes, 'border-')) {
                const r = ratio(border, 'surface');
                if (r < AA_NON_TEXT) problems.push(`${path}: border-${border} is ${fmt(r)}:1`);
            }
        }
        assert.ok(controls > 5, `only ${controls} controls found: the scan is broken`);
        assert.deepEqual(problems, []);
    });
}

check('the first-paint script in app.blade.php picks the same theme as utils/theme.js', () => {
    const blade = read('resources/views/app.blade.php');
    const script = blade.match(/<script>([\s\S]*?)<\/script>/)?.[1];
    assert.ok(script?.includes(`'${STORAGE_KEY}'`), `the first <script> in app.blade.php should read '${STORAGE_KEY}'`);
    // Every stored value (none, both choices, junk, storage blocked) with the OS in light and dark.
    for (const stored of [null, 'light', 'dark', 'purple', 'throws']) {
        for (const systemDark of [false, true]) {
            const html = { dataset: {} };
            vm.runInNewContext(script, {
                document: { documentElement: html },
                localStorage: { getItem: (key) => { if (stored === 'throws') throw new Error('blocked'); return key === STORAGE_KEY ? stored : null; } },
                window: { matchMedia: (query) => ({ matches: query === '(prefers-color-scheme: dark)' && systemDark }) },
            });
            const expected = resolveTheme(parsePreference(stored === 'throws' ? null : stored), systemDark);
            assert.equal(html.dataset.theme, expected, `stored ${stored}, OS ${systemDark ? 'dark' : 'light'}`);
        }
    }
});

console.log(`\n${checks} checks passed`);
