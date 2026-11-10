// Checks the columns and spacer blocks (no server needed):
//   - ColumnsBlock.astro, rendered with Astro's container API, shows every
//     column that has content, in order, with the class for how many there are
//     (three with one empty is laid out as two, one left as the single column),
//     and a column's button only with both a label and a safe URL
//   - the CSS lays them out as expected at phone, tablet and desktop widths:
//     always stacked on a phone, two side by side from 720px, three from
//     960px, and the panel corners square where the block fills the screen;
//     panels share the hero's button colours for their backgrounds
//   - SpacerBlock.astro, also through BlockRenderer: the height (in rem) and
//     colour reach the style attribute, a missing height is the default, and an
//     invalid height or colour is replaced, never copied into the CSS
//   - the CSS: a spacer is full width at every width, sized and coloured by its
//     custom properties, and spaced like any other block
//
//   node scripts/check-blocks.mjs            (from astro/; src/styles/blocks.css)
//   node scripts/check-blocks.mjs --built    (the CSS in dist/, after `npm run build:local`)

import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getViteConfig } from 'astro/config';
import { createServer } from 'vite';
import { computedValue, parseRules, stripComments } from './lib/css.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
const built = process.argv.includes('--built');
const errors = [];
const check = (ok, message) => ok || errors.push(message);

// --- rendering --------------------------------------------------------------------

/** Each column's text and image, by the order they appear in the HTML. */
function columnsIn(html) {
    const section = html.match(/<section class="([^"]*)"/)?.[1].split(/\s+/) ?? [];
    const columns = html.split(/<div class="column(?: [^"]*)?"/).slice(1).map((part) => ({
        text: part.slice(part.indexOf('>') + 1).replace(/<[^>]*>/g, ' ').replace(/[^\w ]+/g, ' ').replace(/\s+/g, ' ').trim().split(' ')[0] ?? '',
        image: part.match(/<img src="([^"]*)" alt="([^"]*)"/)?.slice(1) ?? null,
    }));
    return { classes: section, columns };
}

const col = (html, image = null, alt = null) => ({ html, image, alt });
const CASES = [
    ['two columns', [col('<p>Left</p>'), col('<p>Right</p>')], null, ['Left', 'Right']],
    ['three columns', [col('<p>First</p>'), col('<p>Second</p>'), col('<p>Third</p>')], 'block-columns--three', ['First', 'Second', 'Third']],
    ['three with the middle one empty', [col('<p>First</p>'), col('<p></p>'), col('<p>Third</p>')], null, ['First', 'Third']],
    ['three with only one filled', [col(''), col('<p>Only</p>'), col('&nbsp;')], 'block-columns--single', ['Only']],
];
const MODIFIERS = ['block-columns--single', 'block-columns--three'];

// The container API renders .astro files; Vite compiles them the way the build does.
const viteConfig = await getViteConfig({ server: { middlewareMode: true, hmr: false }, appType: 'custom', logLevel: 'error' }, { logLevel: 'silent' })({ command: 'serve', mode: 'development' });
const server = await createServer(viteConfig);
try {
    const { experimental_AstroContainer: AstroContainer } = await server.ssrLoadModule('astro/container');
    const { default: ColumnsBlock } = await server.ssrLoadModule('/src/components/blocks/ColumnsBlock.astro');
    const container = await AstroContainer.create();
    const render = (data) => container.renderToString(ColumnsBlock, { props: { id: 'cols', data } });

    for (const [name, columns, modifier, texts] of CASES) {
        const result = columnsIn(await render({ columns }));
        check(JSON.stringify(result.columns.map((c) => c.text)) === JSON.stringify(texts), `${name}: shows ${JSON.stringify(result.columns.map((c) => c.text))}, expected ${JSON.stringify(texts)}`);
        check(result.classes.includes('block-columns'), `${name}: no block-columns class`);
        const modifiers = result.classes.filter((c) => MODIFIERS.includes(c));
        check(JSON.stringify(modifiers) === JSON.stringify(modifier ? [modifier] : []), `${name}: classes ${modifiers.join(' ') || '(none)'}, expected ${modifier ?? '(none)'}`);
        console.log(`ok - render: ${name}`);
    }

    // The third column's image and alt text, and the panel classes, reach the HTML.
    const withImage = columnsIn(await render({ variant: 'dark', columns: [col('<p>a</p>'), col('<p>b</p>'), col('<p>c</p>', 'https://cdn.example.com/c.jpg', 'Third image')] }));
    check(JSON.stringify(withImage.columns[2]?.image) === JSON.stringify(['https://cdn.example.com/c.jpg', 'Third image']), `third column image: ${JSON.stringify(withImage.columns[2]?.image)}`);
    check(['block-columns--three', 'block--panel', 'block--dark'].every((c) => withImage.classes.includes(c)), `three on a dark panel: classes ${withImage.classes.join(' ')}`);
    console.log('ok - render: three on a panel, with an image in the third');

    // Buttons: shown with a label and a safe URL, external links get rel; a
    // column with only a button is kept; a half-filled or unsafe one is ignored.
    const buttons = await render({ columns: [
        { html: '<p>Shop</p>', button_label: 'Buy now', button_url: 'https://shop.example.com' },
        { html: '', button_label: 'About us', button_url: '/about' },
        { html: '<p>Label only</p>', button_label: 'Nowhere', button_url: null },
        { html: '', button_label: 'Bad', button_url: 'javascript:alert(1)' },
    ] });
    const anchors = [...buttons.matchAll(/<a class="button button-primary" href="([^"]*)"(?: rel="([^"]*)")?[^>]*>\s*([^<]*?)\s*<\/a>/g)].map((m) => [m[1], m[2] ?? null, m[3]]);
    check(JSON.stringify(anchors) === JSON.stringify([['https://shop.example.com', 'noopener noreferrer', 'Buy now'], ['/about', null, 'About us']]), `column buttons: ${JSON.stringify(anchors)}`);
    check((buttons.match(/class="column column--has-button"/g) ?? []).length === 2, 'column buttons: not exactly two columns marked column--has-button');
    check(columnsIn(buttons).columns.length === 3, `column buttons: ${columnsIn(buttons).columns.length} columns shown, expected 3 (the javascript: one is empty)`);
    check(!buttons.includes('javascript:'), 'column buttons: an unsafe URL reached the HTML');
    console.log('ok - render: column buttons');

    // --- spacer -----------------------------------------------------------------
    const { default: SpacerBlock } = await server.ssrLoadModule('/src/components/blocks/SpacerBlock.astro');
    const { default: BlockRenderer } = await server.ssrLoadModule('/src/components/blocks/BlockRenderer.astro');
    /** The rendered spacer div's attributes, and whether it is empty. */
    const spacerIn = (html) => {
        const match = html.match(/<div class="block block-spacer"([^>]*)><\/div>/);
        if (!match) return null;
        const attributes = Object.fromEntries([...match[1].matchAll(/([\w-]+)="([^"]*)"/g)].map((m) => [m[1], m[2]]));
        return { attributes, style: attributes.style ?? null };
    };
    const SPACERS = [
        // name, data, expected style, expected number of build warnings
        ['the default height', {}, '--spacer-height: 2rem', 0],
        ['a null height and colour', { height: null, background_color: null }, '--spacer-height: 2rem', 0],
        ['the smallest height', { height: 2 }, '--spacer-height: 0.125rem', 0],
        ['an odd height below the old 16 px floor', { height: 15 }, '--spacer-height: 0.9375rem', 0],
        ['a height below the minimum', { height: 1 }, '--spacer-height: 2rem', 1],
        ['a height of zero', { height: 0 }, '--spacer-height: 2rem', 1],
        ['the largest height, with a colour', { height: 240, background_color: '#1B2A4A' }, '--spacer-height: 15rem; --spacer-bg: #1b2a4a', 0],
        ['a dark colour (no contrast check)', { height: 48, background_color: '#000000' }, '--spacer-height: 3rem; --spacer-bg: #000000', 0],
        ['a height above the maximum', { height: 241, background_color: '#ABCDEF' }, '--spacer-height: 2rem; --spacer-bg: #abcdef', 1],
        ['a height as text', { height: '48' }, '--spacer-height: 2rem', 1],
        ['a height that is not whole', { height: 47.5 }, '--spacer-height: 2rem', 1],
        ['a colour name', { height: 32, background_color: 'red' }, '--spacer-height: 2rem', 1],
        ['CSS after a colour', { height: 32, background_color: '#ffffff; position: fixed' }, '--spacer-height: 2rem', 1],
        ['a colour that is not a string', { height: 32, background_color: ['#ffffff'] }, '--spacer-height: 2rem', 1],
    ];
    const warn = console.warn;
    for (const [name, data, style, warnings] of SPACERS) {
        const logged = [];
        console.warn = (...args) => logged.push(args.join(' '));
        let html;
        try {
            html = await container.renderToString(SpacerBlock, { props: { id: 'sp', data } });
        } finally {
            console.warn = warn;
        }
        const spacer = spacerIn(html);
        check(spacer !== null, `spacer, ${name}: no empty div.block.block-spacer in ${html}`);
        check(spacer?.style === style, `spacer, ${name}: style "${spacer?.style}", expected "${style}"`);
        check(spacer?.attributes['aria-hidden'] === 'true' && spacer?.attributes['data-block-id'] === 'sp', `spacer, ${name}: attributes ${JSON.stringify(spacer?.attributes)}`);
        check(logged.length === warnings, `spacer, ${name}: ${logged.length} build warning(s), expected ${warnings}: ${logged.join(' | ')}`);
        console.log(`ok - render: spacer, ${name}`);
    }

    // Through the renderer: the registry knows the type, and a spacer never stops the next block.
    const page = await container.renderToString(BlockRenderer, { props: { slug: 'p', blocks: [
        { id: 's1', type: 'spacer', data: { height: 80, background_color: '#F5F1EA' } },
        { id: 't1', type: 'text', data: { html: '<p>After</p>' } },
        { id: 's2', type: 'spacer', data: { height: 24 } },
    ] } });
    const rendered = [...page.matchAll(/data-block-id="([^"]*)"/g)].map((m) => m[1]);
    check(JSON.stringify(rendered) === JSON.stringify(['s1', 't1', 's2']), `spacer in a page: blocks rendered ${JSON.stringify(rendered)}`);
    check(page.includes('style="--spacer-height: 5rem; --spacer-bg: #f5f1ea"') && page.includes('style="--spacer-height: 1.5rem"'), 'spacer in a page: heights or colour missing');
    console.log('ok - render: spacers in a page, between other blocks');
} finally {
    await server.close();
}

// --- layout ------------------------------------------------------------------------

const cssText = built
    ? readdirSync(join(root, 'dist', '_astro')).filter((file) => file.endsWith('.css')).map((file) => readFileSync(join(root, 'dist', '_astro', file), 'utf8')).join('\n')
    : readFileSync(join(root, 'src', 'styles', 'blocks.css'), 'utf8');
const rules = parseRules(stripComments(cssText));
const normal = (value) => value?.replace(/\s+/g, '');
/** Number of grid tracks: "minmax(0, 1fr)" is 1, "repeat(3, ...)" is 3. */
const tracks = (value) => {
    const repeat = normal(value)?.match(/^repeat\((\d+),/);
    return repeat ? Number(repeat[1]) : normal(value) === 'minmax(0,1fr)' ? 1 : `"${value}"`;
};

const WIDTHS = [375, 719, 720, 959, 960, 1280];
const LAYOUTS = [
    // name, classes, tracks per width, width variable per width
    ['one', ['block', 'block-columns', 'block-columns--single'], [1, 1, 1, 1, 1, 1], ['column', 'column', 'column', 'column', 'column', 'column']],
    ['two', ['block', 'block-columns'], [1, 1, 2, 2, 2, 2], ['wide', 'wide', 'wide', 'wide', 'wide', 'wide']],
    ['three', ['block', 'block-columns', 'block-columns--three'], [1, 1, 1, 1, 3, 3], ['column', 'column', 'column', 'column', 'wide', 'wide']],
];
const WIDTH_VARS = { column: 'var(--column-width)', wide: 'var(--wide-column-width)' };

for (const [name, classes, expectedTracks, expectedWidths] of LAYOUTS) {
    const got = WIDTHS.map((width) => tracks(computedValue(rules, classes, width, 'grid-template-columns')));
    check(JSON.stringify(got) === JSON.stringify(expectedTracks), `${name}: columns side by side at ${WIDTHS.join('/')}px are ${got.join('/')}, expected ${expectedTracks.join('/')}`);
    const widths = WIDTHS.map((width) => normal(computedValue(rules, classes, width, 'width')));
    const expected = expectedWidths.map((w) => normal(WIDTH_VARS[w]));
    check(JSON.stringify(widths) === JSON.stringify(expected), `${name}: widths at ${WIDTHS.join('/')}px are ${widths.join(', ')}, expected ${expected.join(', ')}`);
    console.log(`ok - layout: ${name} column${name === 'one' ? '' : 's'}: ${got.join('/')} side by side at ${WIDTHS.join('/')}px`);
}

// Panels: square corners where the block is as wide as the screen (44rem for
// the reading column, 72.75rem for the wide one), rounded elsewhere.
const PANEL_CORNERS = [
    ['two', ['block', 'block-columns', 'block--panel', 'block--dark'], [true, true, true, true, true, false]],
    ['three', ['block', 'block-columns', 'block-columns--three', 'block--panel', 'block--dark'], [true, false, false, false, true, false]],
];
for (const [name, classes, square] of PANEL_CORNERS) {
    const got = WIDTHS.map((width) => computedValue(rules, classes, width, 'border-radius') === '0');
    check(JSON.stringify(got) === JSON.stringify(square), `${name} on a panel: square corners at ${WIDTHS.join('/')}px are ${got.join('/')}, expected ${square.join('/')}`);
    console.log(`ok - layout: ${name} on a panel: corners`);
}

// Buttons on panels use the hero's colours for the same background, so the hero
// pairs in check-contrast.mjs cover them. A rule that sets them must name each panel.
for (const panel of ['.block--panel.block--brand', '.block--panel.block--dark', '.block--panel).block--light']) {
    const shared = rules.some((rule) => '--button-bg' in rule.declarations && rule.selectors.some((s) => normal(s).includes(normal(panel)) && s.includes('.button-primary')));
    check(shared, `no rule gives a button on ${panel.replace(')', '')} the hero's colours`);
}
console.log('ok - layout: panel buttons share the hero colours');

// Spacer: full width, sized and coloured only by its custom properties, and the
// usual block margin (no rule of its own touches spacing, so the blocks around
// it keep theirs).
const SPACER_CSS = { width: '100%', height: 'var(--spacer-height)', 'background-color': 'var(--spacer-bg,transparent)', margin: '0autovar(--space-block)' };
for (const [property, expected] of Object.entries(SPACER_CSS)) {
    const got = WIDTHS.map((width) => normal(computedValue(rules, ['block', 'block-spacer'], width, property)));
    check(got.every((value) => value === normal(expected)), `spacer: ${property} at ${WIDTHS.join('/')}px is ${got.join(', ')}, expected ${expected}`);
}
const spacerRules = rules.filter((rule) => rule.selectors.some((s) => s.includes('block-spacer')));
const spacing = spacerRules.flatMap((rule) => Object.keys(rule.declarations).filter((p) => /^(margin|padding)/.test(p)));
check(spacerRules.length > 0 && spacing.length === 0, `spacer: its own rules set ${spacing.join(', ') || 'nothing'} for spacing, expected none`);
console.log('ok - layout: spacer is full width and spaced like any other block');

if (errors.length) {
    console.error(`\n${errors.length} problem(s):\n  ${errors.join('\n  ')}`);
    process.exit(1);
}
console.log(`All columns and spacer block checks passed (CSS from ${built ? 'dist/' : 'src/styles/blocks.css'}).`);
