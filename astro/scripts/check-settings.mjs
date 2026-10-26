// Checks normalizeSite() in src/lib/settings.js, the build's second check of
// the site settings from the API, without a test framework or a server.
//
//   node scripts/check-settings.mjs        (from astro/)

import assert from 'node:assert/strict';
import { SITE_DEFAULTS } from '../src/lib/site.js';
import { currentState, navigationFor, normalizePath, normalizeSite } from '../src/lib/settings.js';

const API = 'http://localhost:8000';
const VALID = Object.freeze({
    name: 'Example Studio',
    description: 'Websites for small businesses.',
    footer_text: 'Storgatan 1',
    show_site_name: false,
    theme: { brand: '#7A1F3D', fontBody: 'serif', fontHeading: 'system', corners: 'round', buttons: 'outline' },
    logo: { url: `${API}/storage/9/conversions/logo-logo.png`, width: 240, height: 80 },
    navigation: [
        { label: 'Home', href: '/' },
        { label: 'About', href: '/about' },
        { label: 'Blog', href: '/blog' },
    ],
    front_page: { slug: 'about' },
});

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

/** normalizeSite() with the warnings collected instead of printed. */
function normalize(overrides = {}, origin = API) {
    const warnings = [];
    const site = normalizeSite({ ...structuredClone(VALID), ...overrides }, { apiOrigin: origin, warn: (message) => warnings.push(message) });
    return { site, warnings };
}

test('a valid response is used as given, without warnings', () => {
    const { site, warnings } = normalize();
    assert.deepEqual(warnings, []);
    assert.deepEqual(site, {
        name: 'Example Studio',
        description: 'Websites for small businesses.',
        footerText: 'Storgatan 1',
        showSiteName: false,
        theme: { brand: '#7a1f3d', fontBody: 'serif', fontHeading: 'system', corners: 'round', buttons: 'outline' },
        logo: VALID.logo,
        navigation: VALID.navigation,
        frontPage: { slug: 'about' },
    });
});

test('a response that is not a JSON object throws (the build fails)', () => {
    for (const raw of [null, [], 'text', 42, true]) {
        assert.throws(() => normalizeSite(raw, { apiOrigin: API, warn: () => {} }), /not a JSON object/, JSON.stringify(raw));
    }
});

test('an empty object gives every default, with a warning per field', () => {
    const warnings = [];
    const site = normalizeSite({}, { apiOrigin: API, warn: (m) => warnings.push(m) });
    assert.equal(site.name, SITE_DEFAULTS.name);
    assert.equal(site.showSiteName, true);
    assert.deepEqual(site.navigation, SITE_DEFAULTS.navigation);
    assert.ok(warnings.length >= 4);
});

test('name: 1-80 characters after trimming, plain text', () => {
    assert.equal(normalize({ name: '  Trimmed  ' }).site.name, 'Trimmed');
    assert.equal(normalize({ name: 'å'.repeat(80) }).site.name, 'å'.repeat(80)); // characters, not bytes
    assert.equal(normalize({ name: '😀'.repeat(80) }).site.name, '😀'.repeat(80)); // code points, like the server
    for (const name of ['', '   ', 'a'.repeat(81), 'Tab\there', 'Line\nbreak', null, 42, ['x'], { x: 1 }]) {
        const { site, warnings } = normalize({ name });
        assert.equal(site.name, SITE_DEFAULTS.name, JSON.stringify(name));
        assert.equal(warnings.length, 1);
    }
    // Markup is just text: kept, and escaped by the templates.
    assert.equal(normalize({ name: 'Tom & "Jerry" <b>' }).site.name, 'Tom & "Jerry" <b>');
});

test('description and footer text: null or plain text within the limits', () => {
    assert.equal(normalize({ description: null }).site.description, null);
    assert.equal(normalize({ description: '   ' }).site.description, null);
    assert.equal(normalize({ description: 'a'.repeat(300) }).site.description, 'a'.repeat(300));
    assert.equal(normalize({ description: 'a'.repeat(301) }).site.description, null);
    assert.equal(normalize({ footer_text: 'a'.repeat(200) }).site.footerText, 'a'.repeat(200));
    for (const value of ['a'.repeat(201), 'Two\nlines', 7, {}]) assert.equal(normalize({ footer_text: value }).site.footerText, null, JSON.stringify(value));
    assert.equal(normalize({ footer_text: undefined }).warnings.length, 1);
});

test('show_site_name must be a boolean', () => {
    assert.equal(normalize({ show_site_name: true }).site.showSiteName, true);
    for (const value of ['false', 0, 1, null, undefined]) {
        const { site, warnings } = normalize({ show_site_name: value });
        assert.equal(site.showSiteName, true, JSON.stringify(value));
        assert.equal(warnings.length, 1);
    }
});

test('theme values go through theme.js and each replaced value is reported', () => {
    const { site, warnings } = normalize({ theme: { brand: '#FFFF00', fontBody: 'comic', fontHeading: 'system', corners: 'round', buttons: 'outline' } });
    assert.deepEqual(site.theme, { brand: '#1b2a4a', fontBody: 'inter', fontHeading: 'system', corners: 'round', buttons: 'outline' });
    assert.equal(warnings.length, 2);
    assert.deepEqual(normalize({ theme: 'red' }).site.theme, { brand: '#1b2a4a', fontBody: 'inter', fontHeading: 'inter', corners: 'soft', buttons: 'solid' });
    assert.equal(normalize({ theme: { ...VALID.theme, brand: '</style><script>' } }).site.theme.brand, '#1b2a4a');
});

test('navigation: entries need a text label and a path on this site', () => {
    const navigation = [
        { label: 'Home', href: '/' },
        { label: 'Protocol-relative', href: '//evil.example' },
        { label: 'Backslash', href: '/\\evil.example' },
        { label: 'Absolute', href: 'https://evil.example/' },
        { label: 'Script', href: 'javascript:alert(1)' },
        { label: 'Space', href: '/a b' },
        { label: '', href: '/empty-label' },
        { label: 42, href: '/number-label' },
        { href: '/no-label' },
        null,
        'text',
        { label: '  About  ', href: '/about/' },
    ];
    const { site, warnings } = normalize({ navigation });
    assert.deepEqual(site.navigation, [
        { label: 'Home', href: '/' },
        { label: 'About', href: '/about/' },
    ]);
    assert.equal(warnings.length, 10);
    assert.deepEqual(normalize({ navigation: [] }).site.navigation, []);
    assert.deepEqual(normalize({ navigation: 'x' }).site.navigation, SITE_DEFAULTS.navigation);
});

test('logo: an http(s) URL on the API origin with a size from 1 to 4000, otherwise none', () => {
    const logo = (changes) => normalize({ logo: { ...VALID.logo, ...changes } });
    assert.deepEqual(logo({ width: 4000, height: 1 }).site.logo, { ...VALID.logo, width: 4000, height: 1 });
    assert.deepEqual(normalize({ logo: null }).warnings, []);

    for (const changes of [
        { url: 'https://evil.example/logo.png' },
        { url: 'http://localhost:9000/storage/logo.png' }, // other port: other origin
        { url: 'https://localhost:8000/storage/logo.png' }, // other scheme: other origin
        { url: '/storage/9/logo.png' },
        { url: 'javascript:alert(1)' },
        { url: 'data:image/png;base64,AAAA' },
        { url: 42 },
        { width: 0 },
        { width: 4001 },
        { height: 12.5 },
        { height: '80' },
        { width: undefined },
    ]) {
        const { site, warnings } = logo(changes);
        assert.equal(site.logo, null, JSON.stringify(changes));
        assert.equal(warnings.length, 1);
    }
    assert.equal(normalize({ logo: 'x' }).site.logo, null);
    // Without a known API origin no logo can be on it.
    assert.equal(normalize({}, '').site.logo, null);
});

test('front_page: null or a slug', () => {
    assert.equal(normalize({ front_page: null }).site.frontPage, null);
    for (const value of [{ slug: '' }, { slug: 'a/b' }, { slug: '../x' }, { slug: 'a b' }, { slug: 5 }, 'about', {}]) {
        assert.equal(normalize({ front_page: value }).site.frontPage, null, JSON.stringify(value));
    }
});

test('the front page is left out of the navigation, trailing slashes ignored', () => {
    const site = normalize({ navigation: [{ label: 'Home', href: '/' }, { label: 'About', href: '/about/' }, { label: 'Blog', href: '/blog' }] }).site;
    assert.deepEqual(navigationFor(site).map((item) => item.href), ['/', '/blog']);
    assert.deepEqual(navigationFor({ ...site, frontPage: null }).map((item) => item.href), ['/', '/about/', '/blog']);
    assert.equal(normalizePath('/about/'), '/about');
    assert.equal(normalizePath('/'), '/');
});

test('aria-current: the page itself, or its section', () => {
    assert.equal(currentState('/', '/'), 'page');
    assert.equal(currentState('/blog', '/blog/'), 'page');
    assert.equal(currentState('/blog', '/blog/a-post/'), 'true');
    assert.equal(currentState('/', '/blog/'), undefined);
    assert.equal(currentState('/about', '/about-us/'), undefined);
});

if (failures.length) {
    console.error(`${failures.length} failed, ${passed} passed:\n\n${failures.join('\n\n')}`);
    process.exit(1);
}
console.log(`settings.js: all ${passed} checks passed.`);
