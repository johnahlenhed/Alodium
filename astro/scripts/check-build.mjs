// Checks the built HTML in dist/ (run `npm run build:local` first):
//   - exactly one <h1> per page from the templates (rich text from the CMS
//     can contain its own <h1>s; those are counted and reported separately)
//   - the skip link is the first thing in <body> and points at <main id="main">
//   - the header and footer navigation are the same on every page, and
//     aria-current is on the right links, and only there (check-site.mjs
//     compares the navigation itself with the site settings)
//   - the favicon links are present
//   - no resource is loaded from a host other than the site and the API origin
//     (URLs that come from CMS data, in <main> or the og:image tags, are reported)
//   - which font files the pages load (--fonts=inter,serif to require a set)
//
//   node scripts/check-build.mjs [--fonts=inter]       (from astro/)

import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from 'vite';
import { FONTS } from '../src/lib/fonts.js';
import { currentState } from '../src/lib/settings.js';
import { countH1 } from './lib/html.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
const dist = join(root, 'dist');
const mode = process.argv.includes('--production') ? 'production' : 'development';
const env = loadEnv(mode, root, '');
const allowedHosts = new Set([new URL(env.SITE_URL || 'http://localhost:4321').host, new URL(env.API_URL).host]);
const expectedFonts = process.argv.find((arg) => arg.startsWith('--fonts='))?.slice(8).split(',').filter(Boolean);

const walk = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(join(dir, e.name)) : [join(dir, e.name)]));
const files = walk(dist);
const pages = files.filter((file) => file.endsWith('.html'));
const stylesheets = files.filter((file) => file.endsWith('.css'));

/** "/blog/post/" for dist/blog/post/index.html, "/404" for dist/404.html. */
const pathOf = (file) => {
    const path = `/${relative(dist, file).split(sep).join('/')}`;
    return path.endsWith('/index.html') ? path.slice(0, -'index.html'.length) : path.replace(/\.html$/, '');
};

const errors = [];
const contentHosts = new Map(); // third-party URLs from CMS data, reported, not failed
const contentH1 = [];
const fontsLoaded = new Set();
let referenceNav = null; // the first page's navigation; every page must have the same

for (const file of pages) {
    const html = readFileSync(file, 'utf8');
    const path = pathOf(file);
    const fail = (message) => errors.push(`${path}: ${message}`);

    const body = html.slice(html.indexOf('<body'));
    const h1 = countH1(body);
    if (h1.template !== 1) fail(`${h1.template} <h1> elements from the templates`);
    if (h1.content) contentH1.push(`${path} (${h1.content})`);

    if (!/<body[^>]*>\s*<a class="skip-link" href="#main">/.test(html)) fail('the skip link is not the first element in <body>');
    if ((html.match(/<main id="main"[\s>]/g) ?? []).length !== 1) fail('no single <main id="main">');

    for (const label of ['Main', 'Footer']) {
        const nav = html.match(new RegExp(`<nav[^>]*aria-label="${label}"[^>]*>([\\s\\S]*?)</nav>`))?.[1];
        if (!nav) {
            fail(`no <nav aria-label="${label}">`);
            continue;
        }
        const links = [...nav.matchAll(/<a href="([^"]+)"(?: aria-current="([^"]+)")?>/g)].map(([, href, current]) => ({ href, current }));
        const hrefs = JSON.stringify(links.map((link) => link.href));
        referenceNav ??= hrefs;
        if (hrefs !== referenceNav) fail(`${label} navigation ${hrefs} differs from the other pages' ${referenceNav}`);
        for (const { href, current } of links) {
            const expected = currentState(href, path);
            if (current !== expected) fail(`${label} navigation: ${href} has aria-current=${current ?? 'none'}, expected ${expected ?? 'none'}`);
        }
    }

    for (const tag of ['<link rel="icon" href="/favicon.ico"', '<link rel="icon" type="image/svg+xml" href="/favicon.svg"', '<link rel="apple-touch-icon" href="/apple-touch-icon.png"']) {
        if (!html.includes(tag)) fail(`missing ${tag}>`);
    }

    // Everything the browser fetches by itself: src, srcset, <link href>, CSS url(); and the
    // og/twitter images. Plain <a href> links are not requests, so they are not checked.
    const main = html.match(/<main[\s\S]*<\/main>/)?.[0] ?? '';
    const cmsImages = [...html.matchAll(/<meta\s+(?:property|name)="(?:og|twitter):image"\s+content="([^"]+)"/g)].map((m) => m[1]);
    const urls = [
        ...[...html.matchAll(/\ssrc="([^"]+)"/g)].map((m) => m[1]),
        ...[...html.matchAll(/\ssrcset="([^"]+)"/g)].flatMap((m) => m[1].split(',').map((part) => part.trim().split(/\s+/)[0])),
        ...[...html.matchAll(/<link\b[^>]*\shref="([^"]+)"/g)].map((m) => m[1]),
        ...[...html.matchAll(/url\(\s*['"]?([^'")]+)/g)].map((m) => m[1]),
        ...cmsImages,
    ];
    for (const url of urls) {
        if (/^\/fonts\//.test(url)) fontsLoaded.add(url);
        if (!/^(https?:)?\/\//.test(url)) continue; // relative: same site
        const host = new URL(url, 'http://x').host;
        if (allowedHosts.has(host)) continue;
        if (main.includes(url) || cmsImages.includes(url)) {
            contentHosts.set(host, (contentHosts.get(host) ?? 0) + 1);
        } else {
            fail(`loads ${url} (host ${host})`);
        }
    }
}

for (const file of stylesheets) {
    const css = readFileSync(file, 'utf8');
    for (const [, url] of css.matchAll(/url\(\s*['"]?([^'")]+)/g)) {
        if (/^\/fonts\//.test(url)) fontsLoaded.add(url);
        if (/^(https?:)?\/\//.test(url)) errors.push(`${relative(dist, file)}: loads ${url}`);
    }
    if (/@import/.test(css)) errors.push(`${relative(dist, file)}: uses @import`);
}

const loaded = [...fontsLoaded].sort();
console.log(`${pages.length} pages, ${stylesheets.length} stylesheets checked.`);
console.log(`Font files the pages load: ${loaded.length ? loaded.join(', ') : 'none'}`);
if (expectedFonts) {
    const expected = [...new Set(expectedFonts.map((key) => FONTS[key]?.file ?? `unknown font "${key}"`))].filter(Boolean).sort();
    if (JSON.stringify(loaded) !== JSON.stringify(expected)) errors.push(`expected font files ${expected.join(', ') || 'none'}, got ${loaded.join(', ') || 'none'}`);
}
for (const [host, count] of contentHosts) {
    console.log(`Note: ${count} URL(s) to ${host} in CMS content or og:image tags, not from the site's templates.`);
}
if (contentH1.length) console.log(`Note: rich text from the CMS adds its own <h1> on ${contentH1.join(', ')}.`);

if (errors.length) {
    console.error(`\n${errors.length} problem(s):\n  ${errors.slice(0, 50).join('\n  ')}`);
    process.exit(1);
}
console.log('All checks passed.');
