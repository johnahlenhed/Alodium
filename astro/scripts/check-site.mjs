// Checks the built site in dist/ against the live site settings from
// GET /api/public/site (run `npm run build:local` first, with Laravel running):
//   - the name, footer text and navigation labels are escaped text
//   - header and footer navigation equal the API's, in order, without the front page
//   - the logo <img> has the API's width and height and the right alt text
//   - <head> has the theme variables, data-button and only the selected font files
//   - "/" renders the front page (the same content as /{slug}/) with one <h1>,
//     or, without a front page, the latest 6 posts and a link to /blog/
//   - the front page's /{slug}/ has its canonical at "/" and is not in the sitemap
//   - "/" has a meta and og:description, and WebSite JSON-LD with the site's name
//
//   node scripts/check-site.mjs        (from astro/)

import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from 'vite';
import { FONTS } from '../src/lib/fonts.js';
import { normalizePath } from '../src/lib/settings.js';
import { buildTheme } from '../src/lib/theme.js';
import { countH1, decode } from './lib/html.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
const dist = join(root, 'dist');
const env = loadEnv('development', root, '');
const api = env.API_URL.replace(/\/+$/, '');
const siteUrl = new URL(env.SITE_URL || 'http://localhost:4321');

const errors = [];
const check = (ok, message) => ok || errors.push(message);
const fetchJson = async (path) => {
    const response = await fetch(`${api}${path}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`${api}${path} returned ${response.status}`);
    return response.json();
};

const site = await fetchJson('/site');
const read = (path) => readFileSync(join(dist, path), 'utf8');
const pageFiles = (function walk(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(join(dir, e.name)) : e.name.endsWith('.html') ? [join(dir, e.name)] : []));
})(dist);

const first = (html, regex) => html.match(regex)?.[1];
// A start tag, reading quoted attribute values whole (they may contain ">").
const TAG = (name) => new RegExp(`(<${name}\\b(?:[^>"']|"[^"]*"|'[^']*')*>)`);
const navLinks = (html, label) => {
    const nav = first(html, new RegExp(`<nav[^>]*aria-label="${label}"[^>]*>([\\s\\S]*?)</nav>`)) ?? '';
    return [...nav.matchAll(/<a href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/g)].map(([, href, text]) => ({ label: decode(text.trim()), href: decode(href) }));
};

// --- what the pages should contain ---------------------------------------------

const frontSlug = site.front_page?.slug ?? null;
const expectedNav = site.navigation.filter((item) => !frontSlug || normalizePath(item.href) !== normalizePath(`/${frontSlug}`));
const theme = buildTheme(site.theme);
const expectedFonts = [...new Set([FONTS[site.theme.fontBody]?.file, FONTS[site.theme.fontHeading]?.file].filter(Boolean))].sort();
const year = String(new Date().getFullYear());
// Raw markup from the settings that must never appear unescaped.
const rawMarkup = [site.name, site.description, site.footer_text, ...site.navigation.map((item) => item.label)]
    .filter((text) => typeof text === 'string')
    .flatMap((text) => text.match(/<[^>]*>/g) ?? []);

console.log(`Settings: "${site.name}", front page: ${frontSlug ?? 'none (latest posts)'}, logo: ${site.logo ? `${site.logo.width}x${site.logo.height}` : 'none'}.`);

// --- every page ----------------------------------------------------------------------

for (const file of pageFiles) {
    const html = readFileSync(file, 'utf8');
    const page = file.slice(dist.length);
    const fail = (message) => errors.push(`${page}: ${message}`);

    // Outside attribute values: inside a quoted attribute only " and & need escaping,
    // so Astro leaves < and > there as they are (valid and safe HTML).
    const outsideAttributes = html.replace(/="[^"]*"/g, '=""');
    for (const markup of rawMarkup) if (outsideAttributes.includes(markup)) fail(`contains unescaped "${markup}" from the settings`);

    check(decode(first(html, /<meta property="og:site_name" content="([^"]*)"/) ?? '') === site.name, `${page}: og:site_name is not the site name`);

    for (const label of ['Main', 'Footer']) {
        const links = navLinks(html, label);
        if (JSON.stringify(links) !== JSON.stringify(expectedNav)) fail(`${label} navigation is ${JSON.stringify(links)}, expected ${JSON.stringify(expectedNav)}`);
    }

    const footerName = decode(first(html, /<p class="site-footer__name">([^<]*)<\/p>/) ?? '');
    if (footerName !== `© ${year} ${site.name}`) fail(`footer line is "${footerName}"`);
    const footerText = first(html, /<p class="site-footer__text">([^<]*)<\/p>/);
    if ((footerText === undefined ? null : decode(footerText)) !== site.footer_text) fail(`footer text is ${JSON.stringify(footerText)}`);
    if (!html.includes('<p class="site-footer__note">This site uses cookie-free analytics.</p>')) fail('the analytics line is missing');

    const brand = first(html, /<a class="site-brand" href="\/">([\s\S]*?)<\/a><nav/) ?? '';
    const name = first(brand, /<span class="site-brand__name">([^<]*)<\/span>/);
    if (site.logo) {
        const img = first(brand, TAG('img')) ?? '';
        if (first(img, /src="([^"]*)"/) !== site.logo.url) fail('the logo src is not the API URL');
        if (first(img, /width="(\d+)"/) !== String(site.logo.width) || first(img, /height="(\d+)"/) !== String(site.logo.height)) fail('the logo has no matching width/height');
        // Astro writes alt="" as a bare "alt", which HTML reads as the same empty value.
        const altMatch = img.match(/\salt(?:="([^"]*)")?(?=[\s>/])/);
        const alt = altMatch ? (altMatch[1] ?? '') : undefined;
        if (alt === undefined || decode(alt) !== (site.show_site_name ? '' : site.name)) fail(`the logo alt is ${JSON.stringify(alt)}`);
        if (site.show_site_name ? decode(name ?? '') !== site.name : name !== undefined) fail('the name next to the logo is wrong');
        if (brand.includes('site-brand__mark')) fail('the built-in mark is shown next to an uploaded logo');
    } else {
        if (!brand.includes('site-brand__mark') || decode(name ?? '') !== site.name) fail('without a logo, the mark and the name should be shown');
    }

    if (!html.includes(theme.css)) fail('the theme :root variables are missing');
    if (first(html, /<html lang="en" data-button="([^"]*)"/) !== theme.htmlAttributes['data-button']) fail('data-button is wrong');
    const fonts = [...new Set(html.match(/\/fonts\/[\w.-]+\.woff2/g) ?? [])].sort();
    if (JSON.stringify(fonts) !== JSON.stringify(expectedFonts)) fail(`font files ${fonts.join(', ') || 'none'}, expected ${expectedFonts.join(', ') || 'none'}`);
}

// A page title gets " | <site name>" (pages with an SEO title from the CMS use that as written).
const contactTitle = decode(first(read('contact/index.html'), /<title>([^<]*)<\/title>/) ?? '');
check(contactTitle === `Contact | ${site.name}`, `/contact: the title is "${contactTitle}"`);

// --- "/" ------------------------------------------------------------------------------

const home = read('index.html');
const main = (html) => first(html, /<main[^>]*>([\s\S]*)<\/main>/) ?? '';
const meta = (html, attr, name) => first(html, new RegExp(`<meta ${attr}="${name}" content="([^"]*)"`));
const jsonLd = [...home.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => JSON.parse(m[1]));

check(countH1(home.slice(home.indexOf('<body'))).template === 1, '/: not exactly one <h1>');
check(jsonLd.length === 1 && jsonLd[0]['@type'] === 'WebSite', `/: JSON-LD is ${jsonLd.map((d) => d['@type']).join(', ') || 'missing'}, expected one WebSite`);
check(jsonLd[0]?.name === site.name, '/: the WebSite JSON-LD name is not the site name');
check(site.description === null ? !('description' in (jsonLd[0] ?? {})) : jsonLd[0]?.description === site.description, '/: the WebSite JSON-LD description is wrong');

let expectedDescription = site.description;
if (frontSlug) {
    const pages = await fetchJson('/pages');
    const summary = pages.find((p) => p.slug === frontSlug);
    const page = await fetchJson(`/pages/${encodeURIComponent(frontSlug)}`);
    expectedDescription = page.meta_description || summary.excerpt;

    const slugPage = read(`${frontSlug}/index.html`);
    check(main(home) === main(slugPage), `/: does not render the content of /${frontSlug}/`);
    for (const block of page.blocks ?? []) {
        if (slugPage.includes(`data-block-id="${block.id}"`)) check(home.includes(`data-block-id="${block.id}"`), `/: block ${block.id} is missing`);
    }
    check(first(slugPage, /<link rel="canonical" href="([^"]*)"/) === new URL('/', siteUrl).href, `/${frontSlug}/: the canonical link is not the site root`);
    check(!/"@type":"WebPage"/.test(slugPage), `/${frontSlug}/: has WebPage JSON-LD`);
    const sitemap = read('sitemap-0.xml');
    check(!sitemap.includes(`/${frontSlug}/<`), `/${frontSlug}/ is in the sitemap`);
    check(sitemap.includes(`<loc>${new URL('/', siteUrl).href}</loc>`), '"/" is not in the sitemap');
} else {
    const posts = (await fetchJson('/posts')).slice(0, 6);
    const listed = [...main(home).matchAll(/<h2>\s*<a href="\/blog\/([^"]+)">/g)].map((m) => m[1]);
    check(JSON.stringify(listed) === JSON.stringify(posts.map((p) => p.slug)), `/: lists ${listed.length} posts, expected the latest ${posts.length}`);
    check(posts.length === 0 || /<a class="more-link" href="\/blog\/">All posts/.test(home), '/: the "All posts" link to /blog/ is missing');
}

if (expectedDescription) {
    check(decode(meta(home, 'name', 'description') ?? '') === expectedDescription, `/: meta description is ${JSON.stringify(meta(home, 'name', 'description'))}`);
    check(decode(meta(home, 'property', 'og:description') ?? '') === expectedDescription, '/: og:description is missing or wrong');
} else {
    check(meta(home, 'name', 'description') === undefined, '/: has a meta description although none is set');
}

console.log(`${pageFiles.length} pages checked. Navigation: ${expectedNav.map((item) => item.label).join(' | ')}`);
if (errors.length) {
    console.error(`\n${errors.length} problem(s):\n  ${errors.slice(0, 40).join('\n  ')}`);
    process.exit(1);
}
console.log('All site settings checks passed.');
