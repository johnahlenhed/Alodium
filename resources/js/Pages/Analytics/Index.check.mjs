// Renders the real Analytics/Index.jsx (React's server renderer), the campaign
// link builder included, and fails if it throws or the tables don't show what
// the props hold. Built the same way as Dashboard.check.mjs: only Inertia, the
// layout and the chart are stand-ins.
//
//   node resources/js/Pages/Analytics/Index.check.mjs              built-in cases
//   node resources/js/Pages/Analytics/Index.check.mjs cases.json   [{ name, props, contains: [], lacks: [], html: [], rows: { Campaigns: [[...cells]] } }]
//
// tests/Feature/AnalyticsRenderTest.php runs it with the props the server sends.
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { build } from 'vite';
import react from '@vitejs/plugin-react';

const here = dirname(fileURLToPath(import.meta.url));
const js = resolve(here, '../..');
const tmp = mkdtempSync(join(tmpdir(), 'analytics-check-'));
// Inside the project, so the bundle's imports of react resolve to the project's own copy.
const out = mkdtempSync(join(resolve(js, '../../node_modules/.cache'), 'analytics-check-'));

const stubs = {
    inertia: `import { createElement } from 'react';
export const Head = () => null;
export const Link = ({ href, children, ...rest }) => createElement('a', { href, ...rest }, children);
export const router = { get() {}, post() {} };
export const usePage = () => ({ props: {} });`,
    layout: `import { createElement, Fragment } from 'react';
export default function AuthenticatedLayout({ header, children }) { return createElement(Fragment, null, header, children); }`,
    chart: `export default function VisitsChart() { return null; }`,
};
for (const [name, code] of Object.entries(stubs)) writeFileSync(join(tmp, `${name}.jsx`), code);

/** The text of each row of the table under the <h3> with `title`: [[cell, ...], ...]. */
function tableRows(html, title) {
    const start = html.indexOf(`>${title}</h3>`);
    if (start === -1) return null;
    const table = html.slice(start).match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/)?.[1];
    if (table === undefined) return [];
    const text = (cell) => cell.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();

    return [...table.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map((row) => [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((cell) => text(cell[1])));
}

let failed = 0;
let count = 0;
try {
    await build({
        configFile: false,
        logLevel: 'error',
        plugins: [react()],
        resolve: {
            alias: [
                { find: /^@inertiajs\/react$/, replacement: join(tmp, 'inertia.jsx') },
                { find: /^@\/Layouts\/AuthenticatedLayout$/, replacement: join(tmp, 'layout.jsx') },
                { find: /^@\/Components\/VisitsChart$/, replacement: join(tmp, 'chart.jsx') },
                { find: /^@\//, replacement: `${js}/` },
            ],
        },
        build: { ssr: join(here, 'Index.jsx'), outDir: out, emptyOutDir: true, rolldownOptions: { output: { entryFileNames: 'analytics.mjs' } } },
    });

    const { default: Analytics } = await import(pathToFileURL(join(out, 'analytics.mjs')).href);
    const { createElement } = await import('react');
    const { renderToString } = await import('react-dom/server');
    globalThis.route = (name) => `/${name}`;

    const cases = process.argv[2] ? JSON.parse(readFileSync(process.argv[2], 'utf8')) : builtInCases();
    count = cases.length;
    for (const { name, props, contains = [], lacks = [], html: markup = [], rows = {} } of cases) {
        const problems = [];
        let html = '';
        const errors = [];
        const consoleError = console.error;
        console.error = (...args) => errors.push(args.map(String).join(' ').split('\n')[0]);
        try {
            html = renderToString(createElement(Analytics, props));
        } catch (error) {
            problems.push(`threw: ${error.message}`);
        } finally {
            console.error = consoleError;
        }
        if (errors.length) problems.push(`React logged: ${errors[0]}`);
        const text = html.replace(/<[^>]+>/g, ' ').replace(/&#x27;|&rsquo;|’/g, "'").replace(/&amp;/g, '&').replace(/\s+/g, ' ');
        for (const wanted of contains) if (!text.includes(wanted)) problems.push(`missing "${wanted}"`);
        for (const unwanted of lacks) if (text.includes(unwanted)) problems.push(`should not show "${unwanted}"`);
        for (const wanted of markup) if (!html.includes(wanted)) problems.push(`the HTML has no ${wanted}`);
        for (const [title, expected] of Object.entries(rows)) {
            const got = tableRows(html, title);
            if (JSON.stringify(got) !== JSON.stringify(expected)) problems.push(`${title} rows are ${JSON.stringify(got)}, expected ${JSON.stringify(expected)}`);
        }

        if (problems.length) {
            failed++;
            console.log(`not ok - ${name}\n    ${problems.join('\n    ')}`);
        } else {
            console.log(`ok - ${name}`);
        }
    }
    console.log(failed ? `\n${failed} of ${count} cases failed` : `\n${count} cases passed`);
} finally {
    rmSync(tmp, { recursive: true, force: true });
    rmSync(out, { recursive: true, force: true });
}
process.exit(failed ? 1 : 0);

/** Without a file: an empty period, campaigns with missing values, and props from before the tables. */
function builtInCases() {
    const base = {
        totalVisits: 0, avgDuration: 0, avgScrollDepth: 0, visitsOverTime: [], topPages: [], topSources: [], topReferrers: [],
        deviceBreakdown: [], topCountries: [], campaigns: [], topMediums: [], publicSiteUrl: 'https://example.com', days: 30,
    };
    const campaigns = [
        { campaign: 'autumn-sale', source: 'newsletter', medium: 'email', count: 12, page: '/offer', otherPages: 2, editUrl: '/pages/7/edit' },
        { campaign: 'autumn-sale', source: 'facebook', medium: 'social', count: 5, page: '/offer', otherPages: 1, editUrl: '/pages/7/edit' },
        { campaign: 'launch', source: null, medium: null, count: 1, page: '/', otherPages: 0, editUrl: null },
    ];

    return [
        {
            name: 'no campaign visits',
            props: base,
            contains: ['Campaigns', 'No visits from campaign links in this period.', 'Mediums', 'Campaign link builder', 'Fill in the page address'],
            rows: { Campaigns: [] },
        },
        {
            name: 'campaigns with their source and medium, and mediums',
            props: { ...base, totalVisits: 18, campaigns, topMediums: [{ label: 'email', count: 12 }, { label: 'social', count: 5 }] },
            rows: {
                Campaigns: [
                    ['autumn-sale', 'newsletter', 'email', '/offer and 2 more pages', '12'],
                    ['autumn-sale', 'facebook', 'social', '/offer and 1 more page', '5'],
                    ['launch', 'not set', 'not set', '/', '1'],
                ],
                Mediums: [['email', '12'], ['social', '5']],
            },
            // The page links to its edit screen; a path that isn't a post or page isn't a link.
            html: ['<a href="/pages/7/edit" class="font-medium text-brand-700 hover:underline">/offer</a>', '<span class="text-gray-700">/</span>'],
        },
        {
            name: 'campaigns from a server without the page (older PHP, newer assets)',
            props: { ...base, campaigns: campaigns.map(({ page, otherPages, editUrl, ...row }) => row) },
            rows: { Campaigns: [['autumn-sale', 'newsletter', 'email', '—', '12'], ['autumn-sale', 'facebook', 'social', '—', '5'], ['launch', 'not set', 'not set', '—', '1']] },
        },
        {
            name: 'props from a server before the UTM tables (older PHP, newer assets)',
            props: Object.fromEntries(Object.entries(base).filter(([key]) => !['campaigns', 'topMediums', 'publicSiteUrl'].includes(key))),
            contains: ['Campaigns', 'Mediums', 'Campaign link builder'],
        },
    ];
}
