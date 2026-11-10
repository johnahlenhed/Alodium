// Checks the header and footer layouts (site_settings.header_layout and
// footer_layout, src/lib/layouts.js), without a server:
//   - SiteHeader.astro and SiteFooter.astro, rendered with Astro's container
//     API, get the layout's class (none for "standard", and none for a value
//     not in the list), next to the colour scheme's class; the header in every
//     layout with the built-in mark (no logo), a logo with the name and a logo
//     without it
//   - the brand (mark or logo, and the name) is one element, .site-brand,
//     placed by the layout: where it sits is computed for each layout, and no
//     rule places the mark and an uploaded logo differently
//   - a layout changes nothing else in the HTML: the content and its order
//     (and so the reading and tab order) are the same for every layout
//   - the CSS arranges each layout as described, at phone, tablet and desktop
//     widths, in src/styles/site.css as the browser would apply it
//   - the layout rules only arrange: none sets a colour, background, opacity
//     or variable, so the header and footer background checks
//     (navBackground.js, footerBackground.js) hold for every layout
//
//   node scripts/check-header-footer.mjs        (from astro/)

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getViteConfig } from 'astro/config';
import { createServer } from 'vite';
import { FOOTER_LAYOUTS, HEADER_LAYOUTS, layoutClass } from '../src/lib/layouts.js';
import { isModelledSelector, parseRules, stripComments, winningDeclaration } from './lib/css.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
const errors = [];
const check = (ok, message) => ok || errors.push(message);
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// --- rendering --------------------------------------------------------------------

const NAVIGATION = [
    { label: 'Home', href: '/' },
    { label: 'About', href: '/about' },
    { label: 'Shop', href: 'https://shop.example.com' },
];
const LOGO = { url: 'http://localhost:8000/storage/1/conversions/logo-logo.png', width: 108, height: 36 };
/** The header's brand cases: the built-in mark (no logo), a logo next to the name, a logo alone. */
const BRANDS = {
    mark: { logo: null, showSiteName: true },
    'logo and name': { logo: LOGO, showSiteName: true },
    'logo alone': { logo: LOGO, showSiteName: false },
};
const header = (headerLayout, navBackground = null, brand = 'mark') => ({ name: 'Example Studio', navBackground, headerLayout, ...BRANDS[brand] });
const footer = (footerLayout, footerBackground = null) => ({ name: 'Example Studio', footerText: 'Storgatan 1', footerBackground, footerLayout });
/** The class attribute of the first <tag>, as a list. */
const classesOf = (html, tag) => html.match(new RegExp(`<${tag}\\b[^>]*class="([^"]*)"`))?.[1].split(/\s+/) ?? null;
/** The HTML without the element's own start tag: what a layout must not change. */
const inside = (html, tag) => html.replace(new RegExp(`<${tag}\\b[^>]*>`), '').replace(/\sdata-astro-[\w-]+(="[^"]*")?/g, '');

// The container API renders .astro files; Vite compiles them the way the build does.
const viteConfig = await getViteConfig({ server: { middlewareMode: true, hmr: false }, appType: 'custom', logLevel: 'error' }, { logLevel: 'silent' })({ command: 'serve', mode: 'development' });
const server = await createServer(viteConfig);
try {
    const { experimental_AstroContainer: AstroContainer } = await server.ssrLoadModule('astro/container');
    const { default: SiteHeader } = await server.ssrLoadModule('/src/components/SiteHeader.astro');
    const { default: SiteFooter } = await server.ssrLoadModule('/src/components/SiteFooter.astro');
    const container = await AstroContainer.create();
    const request = new Request('http://localhost:4321/about');
    const renderHeader = (settings) => container.renderToString(SiteHeader, { props: { settings, navigation: NAVIGATION }, request });
    const renderFooter = (settings) => container.renderToString(SiteFooter, { props: { settings, navigation: NAVIGATION }, request });

    const parts = [
        ['header', 'site-header', HEADER_LAYOUTS, renderHeader, header, { color: '#1b2a4a', scheme: 'dark' }],
        ['footer', 'site-footer', FOOTER_LAYOUTS, renderFooter, footer, { color: '#f5f1ea', scheme: 'light' }],
    ];
    for (const [tag, block, layouts, render, settings, colour] of parts) {
        const standard = await render(settings('standard'));
        check(same(classesOf(standard, tag), [block]), `${tag}, standard: classes ${classesOf(standard, tag)?.join(' ')}, expected only ${block} (as before the setting)`);

        for (const layout of layouts) {
            const html = await render(settings(layout));
            const expected = layout === 'standard' ? [block] : [block, `${block}--${layout}`];
            check(same(classesOf(html, tag), expected), `${tag}, ${layout}: classes ${classesOf(html, tag)?.join(' ')}, expected ${expected.join(' ')}`);
            check(inside(html, tag) === inside(standard, tag), `${tag}, ${layout}: the HTML inside the ${tag} differs from the standard layout's`);

            // With a background colour: the scheme's class and --*-bg stay, the layout's class is added.
            const coloured = await render(settings(layout, colour));
            const expectedColoured = [block, `${block}--${colour.scheme}`, ...(layout === 'standard' ? [] : [`${block}--${layout}`])];
            check(same(classesOf(coloured, tag), expectedColoured), `${tag}, ${layout} on ${colour.color}: classes ${classesOf(coloured, tag)?.join(' ')}, expected ${expectedColoured.join(' ')}`);
            check(coloured.includes(`--${tag === 'header' ? 'nav' : 'footer'}-bg: ${colour.color}`), `${tag}, ${layout} on ${colour.color}: the background colour is missing`);
        }

        // Not in the list (the other element's layouts too, or missing): the standard layout.
        const others = (tag === 'header' ? FOOTER_LAYOUTS : HEADER_LAYOUTS).filter((layout) => !layouts.includes(layout));
        for (const layout of [...others, 'transparent', 'constructor', undefined, null, 42]) {
            check(same(classesOf(await render(settings(layout)), tag), [block]), `${tag}, ${JSON.stringify(layout)}: got a layout class`);
        }
        console.log(`ok - render: ${tag}: ${layouts.join(', ')}, with and without a colour; anything else is standard`);
    }

    // The brand in every header layout, with and without a logo: one link, the
    // first thing in the header, holding the mark or the logo and the name as
    // set; and the same HTML as in the standard layout for that brand.
    for (const [brand, { logo, showSiteName }] of Object.entries(BRANDS)) {
        const standard = await renderHeader(header('standard', null, brand));
        for (const layout of HEADER_LAYOUTS) {
            const html = await renderHeader(header(layout, null, brand));
            const link = html.match(/<div class="site-header__inner"[^>]*>\s*<a class="site-brand" href="\/"[^>]*>([\s\S]*?)<\/a>/)?.[1];
            check(link !== undefined, `header ${layout}, ${brand}: the brand link is not the first thing in the header`);
            check(/class="site-brand__mark"/.test(link ?? '') === !logo, `header ${layout}, ${brand}: the built-in mark is ${logo ? 'shown next to a logo' : 'missing'}`);
            check(/<img class="site-brand__logo"/.test(link ?? '') === Boolean(logo), `header ${layout}, ${brand}: the logo is ${logo ? 'missing' : 'shown without one'}`);
            check(/class="site-brand__name"/.test(link ?? '') === (showSiteName || !logo), `header ${layout}, ${brand}: the name is ${showSiteName || !logo ? 'missing' : 'shown'}`);
            check(inside(html, 'header') === inside(standard, 'header'), `header ${layout}, ${brand}: the HTML inside the header differs from the standard layout's`);
        }
    }
    console.log(`ok - render: the brand in every header layout: ${Object.keys(BRANDS).join(', ')}`);

    // The footer's content keeps the HTML's order in every layout: name, menu, text, note.
    const order = (html) => ['site-footer__name', 'site-footer__nav', 'site-footer__text', 'site-footer__note'].map((c) => html.indexOf(`class="${c}"`));
    const footerOrder = order(await renderFooter(footer('standard')));
    check(footerOrder.every((index, i) => index > 0 && (i === 0 || index > footerOrder[i - 1])), `footer: the content is not in the order name, menu, text, note (${footerOrder.join(', ')})`);
    console.log('ok - render: the footer reads name, menu, text, note');
} finally {
    await server.close();
}

// --- layout ------------------------------------------------------------------------

const rules = parseRules(stripComments(readFileSync(join(root, 'src', 'styles', 'site.css'), 'utf8')));
const el = (tag, ...classes) => ({ tag, classes });
const WIDTHS = [375, 639, 640, 719, 720, 1023, 1024, 1280];

/** The value at each width; `properties` is the property, or it and a shorthand that also sets it. */
const at = (chain, properties) => WIDTHS.map((width) => {
    const declaration = winningDeclaration(rules, chain, width, [properties].flat());
    if (!declaration) return undefined;
    // A shorthand ("margin-inline: 0 x") sets the start side with its first value.
    return declaration.property === [properties].flat()[0] ? declaration.value : declaration.value.split(/\s+/)[0];
});
/** Number of grid tracks: none set is 1, "minmax(0, 1fr) auto" is 2, "repeat(3, ...)" is 3. */
const tracks = (value) => {
    if (value === undefined) return 1;
    const normal = value.replace(/\s+/g, '');
    const repeat = normal.match(/^repeat\((\d+),/);
    return repeat ? Number(repeat[1]) : value.split(/\s+(?![^(]*\))/).length;
};
const expectAt = (name, got, expected) => {
    check(same(got, expected), `${name} at ${WIDTHS.join('/')}px: ${got.map(String).join(' / ')}, expected ${expected.map(String).join(' / ')}`);
};
const all = (value) => WIDTHS.map(() => value);
const from = (width, before, after) => WIDTHS.map((w) => (w >= width ? after : before));

// Header: <header> > .site-header__inner > (a.site-brand > svg.site-brand__mark | img.site-brand__logo, span.site-brand__name; nav.site-nav > ul)
const HEADER = {
    standard: { display: all('flex'), direction: all(undefined), align: all('center'), tracks: all(1), navSelf: all(undefined), menu: undefined, menuStart: 'calc(-1*var(--space-3))', logo: 'left center', brand: all('left') },
    'centered-menu': { display: from(1024, 'flex', 'grid'), direction: all('column'), align: from(1024, 'flex-start', 'center'), tracks: from(1024, 1, 3), navSelf: all('center'), menu: 'center', menuStart: '0', logo: 'left center', brand: all('left') },
    centered: { display: all('flex'), direction: all('column'), align: all('center'), tracks: all(1), navSelf: all(undefined), menu: 'center', menuStart: '0', logo: 'center', brand: all('centre') },
    stacked: { display: all('flex'), direction: all('column'), align: all('flex-start'), tracks: all(1), navSelf: all(undefined), menu: undefined, menuStart: 'calc(-1*var(--space-3))', logo: 'left center', brand: all('left') },
};
check(same(Object.keys(HEADER), [...HEADER_LAYOUTS]), `header: this check knows ${Object.keys(HEADER).join(', ')}, layouts.js has ${HEADER_LAYOUTS.join(', ')}`);

/**
 * Where the brand sits across the header, from the rules: in a flex row it is
 * the first item (left, with the menu pushed right); in a flex column its
 * align-self or the header's align-items decides; in the grid it is in the
 * first column, at its justify-self.
 */
const SIDE = { 'flex-start': 'left', start: 'left', center: 'centre', 'flex-end': 'right', end: 'right' };
function brandPlacement(inner, brand, width) {
    const value = (chain, property) => winningDeclaration(rules, chain, width, [property])?.value;
    const display = value(inner, 'display');
    if (display === 'grid') return SIDE[value(brand, 'justify-self') ?? value(inner, 'justify-items')] ?? 'the whole first column';
    if ((value(inner, 'flex-direction') ?? 'row') === 'row') return 'left';
    return SIDE[value(brand, 'align-self') ?? value(inner, 'align-items')] ?? 'the full width';
}

/** What could place the mark or the logo differently from the other inside the brand. */
const PLACING = ['align-self', 'justify-self', 'margin', 'margin-inline', 'margin-inline-start', 'margin-left', 'margin-right', 'position', 'left', 'right', 'inset', 'transform', 'order', 'float'];

for (const [layout, expected] of Object.entries(HEADER)) {
    const headerEl = el('header', 'site-header', ...[layoutClass('site-header', HEADER_LAYOUTS, layout)].filter(Boolean));
    const inner = [headerEl, el('div', 'site-header__inner')];
    const nav = [...inner, el('nav', 'site-nav')];
    const menu = [...nav, el('ul')];
    const brand = [...inner, el('a', 'site-brand')];
    expectAt(`header ${layout}: display`, at(inner, 'display'), expected.display);
    expectAt(`header ${layout}: flex-direction`, at(inner, 'flex-direction'), expected.direction);
    expectAt(`header ${layout}: align-items`, at(inner, 'align-items'), expected.align);
    expectAt(`header ${layout}: columns`, at(inner, 'grid-template-columns').map(tracks), expected.tracks);
    expectAt(`header ${layout}: menu align-self`, at(nav, 'align-self'), expected.navSelf);
    expectAt(`header ${layout}: menu justify-content`, at(menu, 'justify-content'), all(expected.menu));
    expectAt(`header ${layout}: menu start margin`, at(menu, ['margin-inline-start', 'margin-inline']).map((v) => v?.replace(/\s+/g, '')), all(expected.menuStart));
    expectAt(`header ${layout}: logo position`, at([...brand, el('img', 'site-brand__logo')], 'object-position'), all(expected.logo));
    // The menu wraps in every layout (the header has no menu toggle).
    expectAt(`header ${layout}: menu wraps`, at(menu, 'flex-wrap'), all('wrap'));

    // The brand: placed by the layout, the same with the mark as with a logo.
    expectAt(`header ${layout}: brand placement`, WIDTHS.map((width) => brandPlacement(inner, brand, width)), expected.brand);
    for (const property of PLACING) {
        const mark = at([...brand, el('svg', 'site-brand__mark')], property);
        const logo = at([...brand, el('img', 'site-brand__logo')], property);
        const name = at([...brand, el('span', 'site-brand__name')], property);
        check(same(mark, logo) && same(mark, name), `header ${layout}: ${property} differs between the mark (${mark.map(String).join('/')}), the logo (${logo.map(String).join('/')}) and the name (${name.map(String).join('/')})`);
    }
    console.log(`ok - layout: header ${layout} (brand ${[...new Set(expected.brand)].join('/')}, with the mark as with a logo)`);
}

// "Logo left, menu centred" from 1024px: the two outer columns share what the
// menu leaves equally (so it is centred), and the brand's never squeezes it.
const centredMenuColumns = winningDeclaration(rules, [el('header', 'site-header', 'site-header--centered-menu'), el('div', 'site-header__inner')], 1280, ['grid-template-columns'])?.value.replace(/\s+/g, '');
check(centredMenuColumns === 'minmax(max-content,1fr)autominmax(0,1fr)', `header centered-menu: columns are "${centredMenuColumns}", expected minmax(max-content, 1fr) auto minmax(0, 1fr)`);

// Footer: <footer> > .site-footer__inner > (p.site-footer__name, nav.site-footer__nav > ul, p.site-footer__text, p.site-footer__note)
const FOOTER = {
    standard: { tracks: from(640, 1, 2), justify: undefined, text: from(640, undefined, '1/-1'), menu: all(undefined), menuJustify: undefined },
    columns: { tracks: [1, 1, 2, 2, 3, 3, 3, 3], justify: undefined, text: [undefined, undefined, '1/-1', '1/-1', 'auto', 'auto', 'auto', 'auto'], menu: from(720, undefined, 'column'), menuJustify: undefined },
    centered: { tracks: all(1), justify: 'center', text: from(640, undefined, '1/-1'), menu: all(undefined), menuJustify: 'center' },
};
check(same(Object.keys(FOOTER), [...FOOTER_LAYOUTS]), `footer: this check knows ${Object.keys(FOOTER).join(', ')}, layouts.js has ${FOOTER_LAYOUTS.join(', ')}`);
for (const [layout, expected] of Object.entries(FOOTER)) {
    const footerEl = el('footer', 'site-footer', ...[layoutClass('site-footer', FOOTER_LAYOUTS, layout)].filter(Boolean));
    const inner = [footerEl, el('div', 'site-footer__inner')];
    const menu = [...inner, el('nav', 'site-footer__nav'), el('ul')];
    expectAt(`footer ${layout}: columns`, at(inner, 'grid-template-columns').map(tracks), expected.tracks);
    expectAt(`footer ${layout}: justify-items`, at(inner, 'justify-items'), all(expected.justify));
    expectAt(`footer ${layout}: text placement`, at([...inner, el('p', 'site-footer__text')], 'grid-column').map((v) => v?.replace(/\s+/g, '')), expected.text);
    // The analytics note spans the footer wherever there is more than one column.
    expectAt(`footer ${layout}: note placement`, at([...inner, el('p', 'site-footer__note')], 'grid-column').map((v) => v?.replace(/\s+/g, '')), from(640, undefined, '1/-1'));
    expectAt(`footer ${layout}: menu direction`, at(menu, 'flex-direction'), expected.menu);
    expectAt(`footer ${layout}: menu justify-content`, at(menu, 'justify-content'), all(expected.menuJustify));
    console.log(`ok - layout: footer ${layout}`);
}

// --- the layout rules only arrange -------------------------------------------------

const ARRANGING = new Set(['display', 'flex-direction', 'align-items', 'align-self', 'justify-self', 'justify-content', 'justify-items', 'text-align', 'margin-inline', 'grid-template-columns', 'grid-column', 'object-position']);
const layoutClasses = [
    ...HEADER_LAYOUTS.map((layout) => layoutClass('site-header', HEADER_LAYOUTS, layout)),
    ...FOOTER_LAYOUTS.map((layout) => layoutClass('site-footer', FOOTER_LAYOUTS, layout)),
].filter(Boolean);
for (const name of layoutClasses) {
    const own = rules.filter((rule) => rule.selectors.some((s) => new RegExp(`\\.${name}(?![\\w-])`).test(s)));
    check(own.length > 0, `.${name} has no rules in site.css`);
    const other = own.flatMap((rule) => Object.keys(rule.declarations).filter((property) => !ARRANGING.has(property)));
    // The checks above skip selectors they can't evaluate (:has(), >, attributes...), so a layout rule may not use one.
    const unread = own.flatMap((rule) => rule.selectors.filter((selector) => !isModelledSelector(selector)));
    check(unread.length === 0, `.${name}: this check can't evaluate ${unread.join(', ')} (use classes, tags and spaces only)`);
    check(other.length === 0, `.${name} sets ${[...new Set(other)].join(', ')}: a layout may only arrange (${[...ARRANGING].join(', ')}), so the background colour checks hold`);
}
console.log(`ok - layout: ${layoutClasses.map((c) => `.${c}`).join(', ')} only arrange; no colours change`);

if (errors.length) {
    console.error(`\n${errors.length} problem(s):\n- ${errors.join('\n- ')}`);
    process.exit(1);
}
console.log('\nHeader and footer layouts: all checks passed.');
