// Checks the page column in the built site (run `npm run build:local` first):
//   - --gutter is defined once, at :root, with a length value
//   - the column widths are defined once, as variables, from --gutter
//   - .post-list has grid-template-columns: minmax(0, 1fr), its items min-width: 0
//   - there is one container rule for the page column, and no other rule
//     gives page content a width of its own
//   - home, /blog, a post, a CMS page, /contact and 404 all use that rule:
//     their <h1> and main content are matched by it, as siblings (same column)
//   - long unbreakable text wraps (overflow-wrap: anywhere) and pre scrolls
//
//   node scripts/check-layout.mjs        (from astro/)

import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dist = fileURLToPath(new URL('../dist', import.meta.url));
const errors = [];
const check = (ok, message) => ok || errors.push(message);

// --- built CSS -> flat list of { selectors, declarations, at } ----------------

const cssFiles = readdirSync(join(dist, '_astro')).filter((file) => file.endsWith('.css'));
const css = cssFiles.map((file) => readFileSync(join(dist, '_astro', file), 'utf8')).join('\n').replace(/\/\*[\s\S]*?\*\//g, '');

function parseRules(text, at = '') {
    const rules = [];
    let i = 0;
    while (i < text.length) {
        const open = text.indexOf('{', i);
        if (open === -1) break;
        const prelude = text.slice(i, open).trim();
        let depth = 1;
        let j = open + 1;
        while (depth > 0 && j < text.length) {
            if (text[j] === '{') depth++;
            else if (text[j] === '}') depth--;
            j++;
        }
        const body = text.slice(open + 1, j - 1);
        if (prelude.startsWith('@font-face')) {
            // not a style rule
        } else if (prelude.startsWith('@')) {
            rules.push(...parseRules(body, prelude));
        } else {
            const declarations = {};
            for (const part of body.split(/;(?![^(]*\))/)) {
                const colon = part.indexOf(':');
                if (colon > 0) declarations[part.slice(0, colon).trim()] = part.slice(colon + 1).trim();
            }
            rules.push({ selectors: splitTopLevel(prelude), declarations, at });
        }
        i = j;
    }
    return rules;
}

/** Splits on commas outside parentheses. */
function splitTopLevel(text, separator = ',') {
    const parts = [];
    let depth = 0;
    let current = '';
    for (const char of text) {
        if (char === '(') depth++;
        if (char === ')') depth--;
        if (char === separator && depth === 0) {
            parts.push(current.trim());
            current = '';
        } else {
            current += char;
        }
    }
    parts.push(current.trim());
    return parts;
}

const rules = parseRules(css);
const normal = (value) => value?.replace(/\s+/g, '');
/** The value of `property` from the (last, outside @media) rule for `selector` that sets it. */
const valueFor = (selector, property) => rules.filter((rule) => rule.selectors.includes(selector) && !rule.at && property in rule.declarations).at(-1)?.declarations[property];
const ROOT = [':root', ':where(:root)'];

// --- --gutter and the column variables -----------------------------------------

const gutterRules = rules.filter((rule) => '--gutter' in rule.declarations);
check(gutterRules.length === 1, `--gutter is defined in ${gutterRules.length} rules, expected 1`);
const gutter = gutterRules[0];
check(gutter && gutter.selectors.some((s) => ROOT.includes(s)) && !gutter.at, `--gutter is not defined at :root (found on ${gutter?.selectors.join(', ')})`);
const gutterValue = gutter?.declarations['--gutter'] ?? '';
// A length: every number in it has a length unit, inside clamp()/min()/max()/calc() or alone.
const numbers = gutterValue.match(/-?[\d.]+[a-z%]*/gi) ?? [];
check(
    /^((clamp|min|max|calc)\(.*\)|-?[\d.]+[a-z]+)$/i.test(gutterValue) && numbers.length > 0 && numbers.every((n) => /^-?[\d.]+(px|rem|em|vw|vh|vmin|vmax|ch)$/i.test(n)),
    `--gutter is not a length: "${gutterValue}"`,
);

const rootRule = rules.find((rule) => rule.selectors.some((s) => ROOT.includes(s)) && '--column-width' in rule.declarations);
check(normal(rootRule?.declarations['--column-width']) === 'min(100%-2*var(--gutter),var(--content-width))', `--column-width is "${rootRule?.declarations['--column-width']}"`);
check(normal(rootRule?.declarations['--wide-column-width']) === 'min(100%-2*var(--gutter),var(--wide-width))', `--wide-column-width is "${rootRule?.declarations['--wide-column-width']}"`);
const formulaCopies = rules.flatMap((rule) =>
    Object.entries(rule.declarations).filter(([name, value]) => !name.startsWith('--') && /min\(\s*100%\s*-/.test(value)).map(([name]) => `${rule.selectors.join(',')} { ${name} }`),
);
check(formulaCopies.length === 0, `the column formula is copied into: ${formulaCopies.join('; ')}`);

// --- the post list ----------------------------------------------------------------

check(normal(valueFor('.post-list', 'grid-template-columns')) === 'minmax(0,1fr)', '.post-list has no grid-template-columns: minmax(0, 1fr)');
check(valueFor('.post-list li', 'min-width') === '0', '.post-list li has no min-width: 0');
check(valueFor('.post-list', 'padding') === '0', '.post-list does not reset its padding to 0');
const listWidth = rules.filter((rule) => rule.selectors.some((s) => /post-list/.test(s)) && ['width', 'max-width', 'margin-left', 'padding-left', 'margin-inline', 'padding-inline'].some((p) => p in rule.declarations));
check(listWidth.length === 0, `rules that move or resize the post list: ${listWidth.map((r) => r.selectors.join(',')).join('; ')}`);

// --- one container rule ------------------------------------------------------------

const containerRules = rules.filter((rule) => normal(rule.declarations.width) === 'var(--column-width)' && rule.selectors.some((s) => s.includes('.site-main')));
check(containerRules.length === 1, `${containerRules.length} container rules for the page column, expected 1`);
const container = containerRules[0];
// No other rule gives page-level content its own width.
const pageWidths = rules.filter(
    (rule) => rule !== container && rule.selectors.some((s) => s.includes('.site-main')) && ['width', 'max-width', 'min-width'].some((p) => p in rule.declarations),
);
check(pageWidths.length === 0, `other rules set a width on page content: ${pageWidths.map((r) => r.selectors.join(',')).join('; ')}`);

// --- long text ---------------------------------------------------------------------

check(valueFor('body', 'overflow-wrap') === 'anywhere', 'body has no overflow-wrap: anywhere (titles, excerpts)');
check(valueFor('.prose', 'overflow-wrap') === 'anywhere', '.prose has no overflow-wrap: anywhere');
check(valueFor('.block', 'overflow-wrap') === 'anywhere', '.block has no overflow-wrap: anywhere');
check(valueFor('.prose pre', 'overflow-x') === 'auto', '.prose pre does not scroll (overflow-x: auto)');
check(rules.some((rule) => rule.selectors.some((s) => /^\.prose :is\(img,\s*video,\s*iframe/.test(s)) && rule.declarations['max-width'] === '100%'), 'images and embeds in .prose have no max-width: 100%');

// --- which elements the container rule matches on each page ----------------------------

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);

/** The <main> element as a small tree: { tag, classes, children, parent }. */
function mainTree(html) {
    const source = html.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<!--[\s\S]*?-->/g, '');
    let main = null;
    const stack = [];
    for (const [, closing, name, attrs] of source.matchAll(/<(\/?)([a-zA-Z][\w-]*)([^>]*)>/g)) {
        const tag = name.toLowerCase();
        if (closing) {
            if (stack.at(-1)?.tag === tag) stack.pop();
            continue;
        }
        const node = { tag, classes: (attrs.match(/\sclass="([^"]*)"/)?.[1] ?? '').split(/\s+/).filter(Boolean), children: [], parent: stack.at(-1) ?? null };
        node.parent?.children.push(node);
        if (tag === 'main') main = node;
        if (!VOID.has(tag) && !attrs.endsWith('/')) stack.push(node);
    }
    return main;
}

/** Matches one compound selector (tag, .class, *, :not(compound)) against a node. */
function matchesCompound(node, compound) {
    let rest = compound;
    while (rest) {
        let m;
        if ((m = rest.match(/^:not\(([^()]*)\)/))) {
            if (matchesCompound(node, m[1])) return false;
        } else if ((m = rest.match(/^\.([\w-]+)/))) {
            if (!node.classes.includes(m[1])) return false;
        } else if ((m = rest.match(/^\*/))) {
            // any element
        } else if ((m = rest.match(/^([a-z][\w-]*)/i))) {
            if (node.tag !== m[1].toLowerCase()) return false;
        } else {
            throw new Error(`Unsupported selector syntax "${rest}" in "${compound}"`);
        }
        rest = rest.slice(m[0].length);
    }
    return true;
}

/** Matches a complex selector with only child combinators ("a > b > c"). */
function matches(node, selector) {
    if (/[\s+~](?![^(]*\))/.test(selector.replace(/\s*>\s*/g, '>'))) throw new Error(`Unsupported combinator in "${selector}"`);
    const parts = splitTopLevel(selector.replace(/\s*>\s*/g, '>'), '>');
    let current = node;
    for (let i = parts.length - 1; i >= 0; i--) {
        if (!current || !matchesCompound(current, parts[i])) return false;
        current = current.parent;
    }
    return true;
}

const walkTree = (node) => [node, ...node.children.flatMap(walkTree)];
const label = (node) => `${node.tag}${node.classes.map((c) => `.${c}`).join('')}`;

const longPost = readdirSync(join(dist, 'blog')).find((name) => name.startsWith('taumata'));
const PAGES = [
    ['/', 'index.html', 'ul.post-list'],
    ['/blog', 'blog/index.html', 'ul.post-list'],
    [`/blog/${longPost} (long title)`, `blog/${longPost}/index.html`, 'div.prose'],
    ['/page-markup-and-formatting (CMS page)', 'page-markup-and-formatting/index.html', 'div.prose'],
    ['/contact', 'contact/index.html', 'form.contact-form'],
    ['/404', '404.html', 'p'],
];

check(longPost, 'the long-title post is not in the build');
console.log('Elements in the page column (matched by the container rule):');
for (const [name, file, contentLabel] of PAGES) {
    if (!container) break;
    const main = mainTree(readFileSync(join(dist, file), 'utf8'));
    const inColumn = walkTree(main).filter((node) => node !== main && container.selectors.some((s) => matches(node, s)));
    const h1 = inColumn.find((node) => node.tag === 'h1');
    const content = inColumn.find((node) => label(node) === contentLabel);
    check(h1, `${name}: the <h1> is not in the page column`);
    check(content, `${name}: ${contentLabel} is not in the page column`);
    check(!h1 || !content || h1.parent === content.parent, `${name}: the <h1> and ${contentLabel} are in different containers`);
    console.log(`  ${name}: ${inColumn.map(label).join(', ')}`);
}

console.log(`\n--gutter: ${gutterValue} (on ${gutter?.selectors.join(', ')})`);
console.log(`Container rule: ${container?.selectors.join(', ')} { width: ${container?.declarations.width} }`);

if (errors.length) {
    console.error(`\n${errors.length} problem(s):\n  ${errors.join('\n  ')}`);
    process.exit(1);
}
console.log('All layout checks passed.');
