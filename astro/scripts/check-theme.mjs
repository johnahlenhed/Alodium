// Checks src/lib/theme.js and src/lib/fonts.js without a test framework:
// valid settings, every kind of invalid value (including hostile strings),
// that the defaults match the variables in blocks.css, and that each font
// combination only loads and preloads its own files.
//
//   node scripts/check-theme.mjs        (from astro/)

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fontFaceCss, fontPreloadMarkup, FONTS } from '../src/lib/fonts.js';
import { buildTheme, CORNERS, DEFAULT_SETTINGS, resolveSettings } from '../src/lib/theme.js';

let passed = 0;
const failures = [];
function test(name, fn) {
    try {
        fn();
        passed++;
    } catch (error) {
        failures.push(`${name}\n    ${error.message.split('\n').join('\n    ')}`);
    }
}

const DEFAULTS = { brand: '#1b2a4a', fontBody: 'inter', fontHeading: 'inter', corners: 'soft', buttons: 'solid' };

// The whole :root rule theme.js may produce: a 6-digit hex, a stack from the
// font registry, and a radius from the corner table. Nothing else gets in.
const stacks = Object.values(FONTS).map((font) => font.stack.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
const CSS_SHAPE = new RegExp(
    `^:root\\{--color-brand:#[0-9a-f]{6};--font-body:(${stacks.join('|')});--font-heading:(${stacks.join('|')});--radius:(${Object.values(CORNERS).join('|')})\\}$`,
);

function assertSafe(theme) {
    assert.match(theme.css, CSS_SHAPE);
    assert.deepEqual(Object.keys(theme.htmlAttributes), ['data-button']);
    assert.ok(['solid', 'outline'].includes(theme.htmlAttributes['data-button']));
    for (const font of theme.fontsToLoad) assert.ok(Object.values(FONTS).includes(font), 'fontsToLoad must be registry entries');
}

// --- defaults ----------------------------------------------------------------

test('no settings gives the defaults', () => {
    for (const input of [undefined, null, {}]) {
        assert.deepEqual(resolveSettings(input), DEFAULTS);
        const theme = buildTheme(input);
        assertSafe(theme);
        assert.equal(theme.css, `:root{--color-brand:#1b2a4a;--font-body:${FONTS.inter.stack};--font-heading:${FONTS.inter.stack};--radius:6px}`);
        assert.deepEqual(theme.htmlAttributes, { 'data-button': 'solid' });
        assert.deepEqual(theme.fontsToLoad, [FONTS.inter]);
    }
});

test('DEFAULT_SETTINGS cannot be changed at runtime', () => {
    assert.ok(Object.isFrozen(DEFAULT_SETTINGS));
});

test('the defaults match the variables in blocks.css', () => {
    const css = readFileSync(new URL('../src/styles/blocks.css', import.meta.url), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    const root = css.match(/:where\(:root\)\s*\{([^}]*)\}/)[1];
    const value = (name) => root.match(new RegExp(`${name}\\s*:\\s*([^;]+);`))[1].trim();
    assert.equal(value('--color-brand').toLowerCase(), DEFAULTS.brand);
    assert.equal(value('--font-body'), FONTS[DEFAULTS.fontBody].stack);
    assert.equal(value('--font-heading'), FONTS[DEFAULTS.fontHeading].stack);
    assert.equal(value('--radius'), CORNERS[DEFAULTS.corners]);
});

// --- valid values --------------------------------------------------------------

test('valid values are used', () => {
    const settings = { brand: '#7A1F3D', fontBody: 'serif', fontHeading: 'system', corners: 'round', buttons: 'outline' };
    assert.deepEqual(resolveSettings(settings), { ...settings, brand: '#7a1f3d' });
    const theme = buildTheme(settings);
    assertSafe(theme);
    assert.equal(theme.css, `:root{--color-brand:#7a1f3d;--font-body:${FONTS.serif.stack};--font-heading:${FONTS.system.stack};--radius:14px}`);
    assert.deepEqual(theme.htmlAttributes, { 'data-button': 'outline' });
});

test('each allowed value of each setting', () => {
    for (const brand of ['#1B2A4A', '#7A1F3D', '#0B5D3B', '#000000', '#767676']) assert.equal(resolveSettings({ brand }).brand, brand.toLowerCase());
    for (const font of Object.keys(FONTS)) {
        assert.equal(resolveSettings({ fontBody: font }).fontBody, font);
        assert.equal(resolveSettings({ fontHeading: font }).fontHeading, font);
    }
    for (const [corners, radius] of Object.entries(CORNERS)) assert.ok(buildTheme({ corners }).css.endsWith(`--radius:${radius}}`));
    for (const buttons of ['solid', 'outline']) assert.equal(buildTheme({ buttons }).htmlAttributes['data-button'], buttons);
});

test('one invalid value only resets that setting', () => {
    assert.deepEqual(resolveSettings({ brand: 'red', fontBody: 'serif', corners: 'sharp' }), { ...DEFAULTS, fontBody: 'serif', corners: 'sharp' });
});

// --- invalid values --------------------------------------------------------------

const HOSTILE = [
    'red; background:url(//evil)',
    '</style><script>alert(1)</script>',
    '#1B2A4A;}body{display:none',
    '#1B2A4A</style>',
    "'; x: '",
    '\n#1B2A4A',
    '#1B2A4A\n',
    ' #1B2A4A',
    '1B2A4A',
    '#1B2A4AFF',
    '#1B2A4G',
    'rgb(27, 42, 74)',
    'var(--color-text)',
    'expression(alert(1))',
    '',
    '__proto__',
    'constructor',
    'toString',
    'hasOwnProperty',
    'Inter',
    'INTER',
    'inter ',
    'solid; color:red',
];
const NON_STRINGS = [null, undefined, 0, 1, 255, NaN, true, false, {}, [], ['#1B2A4A'], { toString: () => '#7A1F3D' }, () => '#7A1F3D', Symbol('x'), 10n];

test('hostile strings fall back to the default for every setting', () => {
    for (const key of Object.keys(DEFAULTS)) {
        for (const value of HOSTILE) {
            const theme = buildTheme({ [key]: value });
            assertSafe(theme);
            assert.deepEqual(resolveSettings({ [key]: value }), DEFAULTS, `${key}: ${JSON.stringify(value)}`);
        }
    }
});

test('non-string values fall back to the default for every setting', () => {
    for (const key of Object.keys(DEFAULTS)) {
        for (const value of NON_STRINGS) {
            assertSafe(buildTheme({ [key]: value }));
            assert.deepEqual(resolveSettings({ [key]: value }), DEFAULTS, `${key}: ${String(typeof value)}`);
        }
    }
});

test('3-digit and 8-digit hex colours are rejected', () => {
    for (const brand of ['#fff', '#000', '#123', '#1B2A4A80']) assert.equal(resolveSettings({ brand }).brand, DEFAULTS.brand, brand);
});

test('brand colours below 4.5:1 against white are rejected', () => {
    // #ffff00 is 1.07:1, #777777 is 4.48:1 (just below), #767676 is 4.54:1 (just above).
    for (const brand of ['#ffff00', '#ffffff', '#777777', '#2a9df4', '#ff6600']) assert.equal(resolveSettings({ brand }).brand, DEFAULTS.brand, brand);
    assert.equal(resolveSettings({ brand: '#767676' }).brand, '#767676');
});

test('settings that are not a plain object give the defaults', () => {
    for (const settings of ['#7A1F3D', 42, true, [], ['#7A1F3D'], () => {}, Symbol('x')]) {
        assert.deepEqual(resolveSettings(settings), DEFAULTS);
        assertSafe(buildTheme(settings));
    }
});

test('inherited values are ignored', () => {
    const inherited = Object.create({ brand: '#7A1F3D', buttons: 'outline' });
    assert.deepEqual(resolveSettings(inherited), DEFAULTS);
    assert.deepEqual(resolveSettings(JSON.parse('{"__proto__": {"brand": "#7A1F3D"}}')), DEFAULTS);
});

test('an object without a prototype works', () => {
    const settings = Object.assign(Object.create(null), { brand: '#0B5D3B', corners: 'sharp' });
    assert.deepEqual(resolveSettings(settings), { ...DEFAULTS, brand: '#0b5d3b', corners: 'sharp' });
});

test('a getter is read once, so it cannot pass the check with one value and return another', () => {
    let reads = 0;
    const settings = {
        get brand() {
            reads++;
            return reads === 1 ? '#7A1F3D' : 'red;}</style><script>alert(1)</script>';
        },
    };
    const theme = buildTheme(settings);
    assertSafe(theme);
    assert.ok(theme.css.includes('--color-brand:#7a1f3d;'));
    assert.equal(reads, 1);
});

test('no hostile input ever reaches the CSS', () => {
    for (const value of HOSTILE) {
        const theme = buildTheme({ brand: value, fontBody: value, fontHeading: value, corners: value, buttons: value });
        for (const bad of ['<', '>', 'url(', 'script', 'evil', '}body', 'expression', '__proto__']) assert.ok(!theme.css.includes(bad), bad);
    }
});

// --- fonts ----------------------------------------------------------------------

const urls = (text) => [...text.matchAll(/\/fonts\/[\w.-]+\.woff2/g)].map((match) => match[0]);

test('each font combination loads and preloads only its own files, each once', () => {
    for (const fontBody of Object.keys(FONTS)) {
        for (const fontHeading of Object.keys(FONTS)) {
            const { fontsToLoad } = buildTheme({ fontBody, fontHeading });
            const expected = [...new Set([FONTS[fontBody].file, FONTS[fontHeading].file])].filter(Boolean).sort();
            const label = `body ${fontBody}, headings ${fontHeading}`;

            const faces = fontFaceCss(fontsToLoad);
            const preloads = fontPreloadMarkup(fontsToLoad);
            assert.deepEqual(urls(faces).sort(), expected, `@font-face for ${label}`);
            assert.deepEqual(urls(preloads).sort(), expected, `preload for ${label}`);
            assert.equal((faces.match(/@font-face/g) ?? []).length, expected.length * 2, `web font + fallback face for ${label}`);
            assert.equal((preloads.match(/<link /g) ?? []).length, expected.length, `one preload per file for ${label}`);
            for (const font of fontsToLoad) assert.ok(faces.includes(`font-family:'${font.fallback.family}'`), `fallback face for ${label}`);
        }
    }
});

test('the system font option loads nothing', () => {
    const { fontsToLoad } = buildTheme({ fontBody: 'system', fontHeading: 'system' });
    assert.deepEqual(fontsToLoad, []);
    assert.equal(fontFaceCss(fontsToLoad), '');
    assert.equal(fontPreloadMarkup(fontsToLoad), '');
});

test('font faces use swap and the preloads are crossorigin woff2', () => {
    const fonts = [FONTS.inter, FONTS.serif];
    for (const rule of fontFaceCss(fonts).split('\n').filter((line) => line.includes('url('))) assert.ok(rule.includes('font-display:swap'));
    for (const link of fontPreloadMarkup(fonts).split('\n')) assert.match(link, /^<link rel="preload" href="\/fonts\/[\w.-]+\.woff2" as="font" type="font\/woff2" crossorigin>$/);
});

test('every registered font file exists in public/', () => {
    for (const font of Object.values(FONTS).filter((f) => f.file)) {
        assert.ok(readFileSync(new URL(`../public${font.file}`, import.meta.url)).length > 0, font.file);
    }
});

// ---------------------------------------------------------------------------------

if (failures.length) {
    console.error(`${failures.length} failed, ${passed} passed:\n\n${failures.join('\n\n')}`);
    process.exit(1);
}
console.log(`theme.js and fonts.js: all ${passed} checks passed.`);
