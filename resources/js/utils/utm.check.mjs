// Checks the UTM rules in the browser code against the server's examples
// (tests/Fixtures/utm-values.json, also run against App\Support\UtmValue by
// tests/Unit/UtmValueTest.php), without a browser:
//   - cleanUtm() in utm.js (the campaign link builder)
//   - public/tracker.js itself, run in a sandbox with a stand-in window: what
//     it sends to /api/collect for each example, that utm_term and
//     utm_content are never sent (but are removed from the address bar), and
//     that other query parameters and the #fragment are kept
//   - buildCampaignUrl(): the tagged links the builder makes
//
//   node resources/js/utils/utm.check.mjs     (tests/Feature/UtmChecksTest.php runs it too)
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { buildCampaignUrl, cleanUtm, inspectUtm, UTM_MAX_LENGTH } from './utm.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const fixture = JSON.parse(readFileSync(resolve(root, 'tests/Fixtures/utm-values.json'), 'utf8'));
const trackerSource = readFileSync(resolve(root, 'public/tracker.js'), 'utf8');

let passed = 0;
const failures = [];
function check(name, fn) {
    try {
        fn();
        passed++;
    } catch (error) {
        failures.push(`${name}\n    ${error.message.split('\n').join('\n    ')}`);
    }
}
const show = (value) => JSON.stringify(value).slice(0, 60);

/**
 * Runs the real tracker.js once for a page address, in a sandbox standing in
 * for the browser. Returns what it POSTed to /collect and where the address
 * bar ended up.
 */
function runTracker(address) {
    const url = new URL(address);
    const sent = [];
    let shown = url.pathname + url.search + url.hash;
    const noop = () => {};
    const window = {
        location: { pathname: url.pathname, search: url.search, hash: url.hash, hostname: url.hostname },
        innerWidth: 1280,
        innerHeight: 800,
        scrollY: 0,
        addEventListener: noop,
    };
    const context = vm.createContext({
        window,
        document: { referrer: '', addEventListener: noop, documentElement: { scrollHeight: 800 }, visibilityState: 'visible' },
        navigator: { language: 'sv-SE' },
        history: { state: null, replaceState: (state, title, next) => { shown = next; } },
        URLSearchParams,
        fetch: (path, options) => {
            sent.push({ path, body: JSON.parse(options.body) });
            return Promise.resolve({ ok: false });
        },
    });
    vm.runInContext(trackerSource, context);
    const collect = sent.filter((request) => request.path === '/api/collect');
    assert.equal(collect.length, 1, `${address}: ${collect.length} requests to /api/collect`);

    return { payload: collect[0].body, shown };
}

// --- the shared examples ----------------------------------------------------------

check('utm.js cleanUtm(): every example as the fixture says', () => {
    assert.ok(fixture.strings.length > 30);
    for (const { value, stored } of fixture.strings) assert.equal(cleanUtm(value), stored, show(value));
    for (const value of fixture.notStrings) assert.equal(cleanUtm(value), null, show(value));
});

check('tracker.js sends every example as the fixture says, as utm_source, utm_medium and utm_campaign', () => {
    for (const { value, stored } of fixture.strings) {
        for (const key of ['utm_source', 'utm_medium', 'utm_campaign']) {
            const { payload } = runTracker(`https://example.com/landing/?${key}=${encodeURIComponent(value)}`);
            assert.equal(payload[key], stored, `${key}=${show(value)}`);
        }
    }
});

// --- what the tracker sends and leaves in the address bar ---------------------------

check('tracker.js never sends utm_term or utm_content, and removes them from the address bar', () => {
    const { payload, shown } = runTracker(
        'https://example.com/landing/?ref=keep&utm_source=Newsletter&utm_medium=Email&utm_campaign=Autumn%20Sale&utm_term=anna%40example.com&utm_content=subscriber-123456789#prices',
    );
    assert.deepEqual(
        Object.keys(payload).filter((key) => key.startsWith('utm_')),
        ['utm_source', 'utm_medium', 'utm_campaign'],
    );
    assert.deepEqual([payload.utm_source, payload.utm_medium, payload.utm_campaign], ['newsletter', 'email', 'autumn sale']);
    assert.ok(!JSON.stringify(payload).includes('anna'), 'the term reached the payload');
    assert.ok(!JSON.stringify(payload).includes('123456789'), 'the content reached the payload');
    assert.equal(shown, '/landing/?ref=keep#prices');
});

check('tracker.js: only utm_term or utm_content in the address: removed, and nothing UTM is sent', () => {
    const { payload, shown } = runTracker('https://example.com/a/?utm_content=x1&utm_term=y#top');
    assert.deepEqual([payload.utm_source, payload.utm_medium, payload.utm_campaign], [null, null, null]);
    assert.equal(shown, '/a/#top');
});

check('tracker.js: no UTM parameters: the address is left alone', () => {
    const { payload, shown } = runTracker('https://example.com/a/?q=shoes#top');
    assert.deepEqual([payload.utm_source, payload.utm_medium, payload.utm_campaign], [null, null, null]);
    assert.equal(shown, '/a/?q=shoes#top');
});

check('tracker.js: a dropped value still sends the page view, with the other values', () => {
    const { payload } = runTracker('https://example.com/a/?utm_source=anna%40example.com&utm_medium=email&utm_campaign=x'.concat('y'.repeat(300)));
    assert.equal(payload.url, '/a/');
    assert.equal(payload.utm_source, null);
    assert.equal(payload.utm_medium, 'email');
    assert.equal(payload.utm_campaign, null, 'a 301-letter word looks like a code');
});

// --- the link builder ---------------------------------------------------------------

check('buildCampaignUrl(): a link with the three values as they are stored', () => {
    const { url, errors, notes } = buildCampaignUrl(' https://example.com/shop/ ', { source: ' Newsletter ', medium: 'Email', campaign: 'Autumn Sale 2026' });
    assert.deepEqual(errors, {});
    assert.equal(url, 'https://example.com/shop/?utm_source=newsletter&utm_medium=email&utm_campaign=autumn+sale+2026');
    assert.deepEqual(notes, { source: 'Stored as “newsletter”.', medium: 'Stored as “email”.', campaign: 'Stored as “autumn sale 2026”.' });
    // What the tracker then reads from it is exactly that.
    const { payload } = runTracker(url);
    assert.deepEqual([payload.utm_source, payload.utm_medium, payload.utm_campaign], ['newsletter', 'email', 'autumn sale 2026']);
});

check('buildCampaignUrl(): other parameters and the #fragment are kept; existing UTM parameters replaced, term and content removed', () => {
    const { url } = buildCampaignUrl('https://example.com/a/?q=1&utm_source=old&utm_term=x&utm_content=y#top', { source: 'facebook', medium: 'social', campaign: 'launch' });
    assert.equal(url, 'https://example.com/a/?q=1&utm_source=facebook&utm_medium=social&utm_campaign=launch#top');
});

check('buildCampaignUrl(): special characters are encoded and survive the round trip', () => {
    const { url } = buildCampaignUrl('https://example.com/', { source: 'partner&co', medium: 'referral', campaign: 'höst #1 = 50%' });
    assert.equal(url, 'https://example.com/?utm_source=partner%26co&utm_medium=referral&utm_campaign=h%C3%B6st+%231+%3D+50%25');
    const { payload } = runTracker(url);
    assert.deepEqual([payload.utm_source, payload.utm_campaign], ['partner&co', 'höst #1 = 50%']);
});

check('buildCampaignUrl(): no link while something is missing or would be dropped, with the reason', () => {
    const empty = buildCampaignUrl('', { source: '', medium: '  ', campaign: '' });
    assert.equal(empty.url, null);
    assert.deepEqual(Object.keys(empty.errors).sort(), ['address', 'campaign', 'medium', 'source']);
    assert.equal(empty.errors.source, 'Required.');

    for (const address of ['example.com/shop', 'ftp://example.com/', 'javascript:alert(1)', '/shop']) {
        assert.ok(buildCampaignUrl(address, { source: 'a', medium: 'b', campaign: 'c' }).errors.address, address);
    }
    const dropped = buildCampaignUrl('https://example.com/', { source: 'anna@example.com', medium: 'id-12345678', campaign: '5f2b9c1e' });
    assert.equal(dropped.url, null);
    assert.match(dropped.errors.source, /email address/);
    assert.match(dropped.errors.medium, /long number/);
    assert.match(dropped.errors.campaign, /code or an id/);
});

check('buildCampaignUrl(): a long label is shortened, and says so', () => {
    const { url, notes } = buildCampaignUrl('https://example.com/', { source: 'newsletter', medium: 'email', campaign: 'word '.repeat(30) });
    assert.equal(notes.campaign, `Shortened to ${UTM_MAX_LENGTH} characters.`);
    assert.equal(new URL(url).searchParams.get('utm_campaign').length, 99, '100 characters, the trailing space trimmed');
    assert.equal(inspectUtm('word '.repeat(30)).shortened, true);
});

if (failures.length) {
    console.error(`${failures.length} UTM check(s) failed:\n- ${failures.join('\n- ')}`);
    process.exit(1);
}
console.log(`utm.js, tracker.js and the link builder: all ${passed} checks passed.`);
