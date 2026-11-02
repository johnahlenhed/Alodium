// Renders the real Dashboard.jsx (React's server renderer) with dashboard props,
// and fails if it throws or shows the wrong sections. A render error blanks the
// whole page in the browser, as when the admin assets were older than the
// server's PHP (siteStatus.notices vs warnings), so this is checked here, where
// the PHP tests can't see it.
//
//   node resources/js/Pages/Dashboard.check.mjs              built-in cases
//   node resources/js/Pages/Dashboard.check.mjs cases.json   [{ name, props, contains: [], lacks: [] }]
//
// tests/Feature/DashboardRenderTest.php runs it with the props the server
// actually sends to an admin and to an editor.
//
// Only Inertia (no server here), the layout and the chart are stand-ins; the
// dashboard's own components are the real ones.
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { build } from 'vite';
import react from '@vitejs/plugin-react';

const here = dirname(fileURLToPath(import.meta.url));
const js = resolve(here, '..');
const tmp = mkdtempSync(join(tmpdir(), 'dashboard-check-'));
// Inside the project, so the bundle's imports of react and lucide-react resolve to
// the project's own copies (one React, shared with the renderer below).
const out = mkdtempSync(join(resolve(js, '../../node_modules/.cache'), 'dashboard-check-'));

const stubs = {
    inertia: `import { createElement } from 'react';
export const Head = () => null;
export const Link = ({ href, children, ...rest }) => createElement('a', { href, ...rest }, children);
export const router = { get() {}, post() {} };
export const usePage = () => ({ props: globalThis.__pageProps });`,
    layout: `import { createElement, Fragment } from 'react';
export default function AuthenticatedLayout({ header, children }) { return createElement(Fragment, null, header, children); }`,
    chart: `export default function VisitsChart() { return null; }`,
};
for (const [name, code] of Object.entries(stubs)) writeFileSync(join(tmp, `${name}.jsx`), code);

let failed = 0;
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
        build: { ssr: join(here, 'Dashboard.jsx'), outDir: out, emptyOutDir: true, rolldownOptions: { output: { entryFileNames: 'dashboard.mjs' } } },
    });

    const { default: Dashboard } = await import(pathToFileURL(join(out, 'dashboard.mjs')).href);
    const { createElement } = await import('react');
    const { renderToString } = await import('react-dom/server');
    globalThis.route = (name) => `/${name}`;

    const cases = process.argv[2] ? JSON.parse(readFileSync(process.argv[2], 'utf8')) : builtInCases();

    for (const { name, props, contains = [], lacks = [] } of cases) {
        globalThis.__pageProps = { auth: { user: { id: 1, name: 'Test' } }, ...props };
        const problems = [];
        let html = '';
        // React logs caught render errors; keep them, as the reason for a failure.
        const errors = [];
        const consoleError = console.error;
        console.error = (...args) => errors.push(args.map(String).join(' ').split('\n')[0]);
        try {
            html = renderToString(createElement(Dashboard, props));
        } catch (error) {
            problems.push(`threw: ${error.message}`);
        } finally {
            console.error = consoleError;
        }
        const text = html.replace(/<[^>]+>/g, ' ').replace(/&#x27;|&rsquo;|’/g, "'").replace(/\s+/g, ' ');
        if (html.includes('couldn')) problems.push(`a section fell back to "couldn't be shown": ${errors[0] ?? ''}`);
        for (const wanted of contains) if (!text.includes(wanted)) problems.push(`missing "${wanted}"`);
        for (const unwanted of lacks) if (text.includes(unwanted)) problems.push(`should not show "${unwanted}"`);

        if (problems.length) {
            failed++;
            console.log(`not ok - ${name}\n    ${problems.join('\n    ')}`);
        } else {
            console.log(`ok - ${name}`);
        }
    }
    console.log(failed ? `\n${failed} of ${cases.length} cases failed` : `\n${cases.length} cases passed`);
} finally {
    rmSync(tmp, { recursive: true, force: true });
    rmSync(out, { recursive: true, force: true });
}
process.exit(failed ? 1 : 0);

/** Without a file: both roles' shapes, and the mismatched shapes a deploy can produce. */
function builtInCases() {
    const content = { publishedPosts: 0, publishedPages: 0, drafts: 0, scheduled: 0, nextScheduledAt: null, missingMetaCount: 0, missingMeta: [] };
    const submissions = { unread: 0, latest: [] };
    const editor = { userName: 'Ed', publicSiteUrl: null, content, submissions };
    const visitors = { days: 30, pageViews: 0, previousPageViews: 0, change: null, isNew: false, avgDuration: 0, avgScrollDepth: 0, daily: [], timezone: 'UTC' };
    const siteStatus = {
        lastRebuild: null, lastBackup: null, offsite: { configured: false, lastUploadAt: null, ageHours: null },
        geoip: { present: false, readable: false, updatedAt: null, ageHours: null }, queuedJobs: 0, failedJobs: 0,
        notices: [{ level: 'info', message: 'Visitor countries aren\'t shown yet.', detail: 'GeoIP missing' }],
    };
    const admin = { ...editor, userName: 'Ada', visitors, topContent: [], sources: [], siteStatus, gettingStarted: null };
    const { notices, ...withoutNotices } = siteStatus;

    return [
        { name: 'admin', props: admin, contains: ['Site status', 'Page views', 'Content', "Visitor countries aren't shown yet."] },
        { name: 'editor (no admin-only props)', props: editor, contains: ['Content', 'Submissions'], lacks: ['Site status', 'Page views', 'Getting started'] },
        // Older PHP than the assets: the plain-string warnings it sent are shown.
        { name: 'admin, server sends the old warnings', props: { ...admin, siteStatus: { ...withoutNotices, warnings: ['The last rebuild failed.'] } }, contains: ['Needs attention:', 'The last rebuild failed.'] },
        { name: 'admin, site status without notices', props: { ...admin, siteStatus: withoutNotices }, contains: ['No problems found.'] },
        { name: 'admin, site status with nothing in it', props: { ...admin, siteStatus: {} }, contains: ['Site status'] },
        { name: 'admin, without siteStatus at all', props: { ...admin, siteStatus: undefined }, contains: ['Page views'], lacks: ['Site status'] },
    ];
}
