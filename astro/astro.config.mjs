// @ts-check
import sitemap from '@astrojs/sitemap';
import { defineConfig } from 'astro/config';
import { readFileSync } from 'node:fs';
import { loadEnv } from 'vite';

// .env files are not loaded into import.meta.env while the config itself is
// evaluated, so load them here. `astro build` uses production mode
// (.env + .env.production); `npm run build:local` uses development (.env only).
const mode = process.argv.includes('--mode')
    ? process.argv[process.argv.indexOf('--mode') + 1]
    : process.argv.includes('dev') ? 'development' : 'production';
const env = loadEnv(mode, process.cwd(), '');

/*
 * Sitemap: leave out every page whose canonical link points to another URL.
 * That is the front page's own /slug/ (its canonical is "/"), and any page that
 * does the same later. The sitemap is written after the pages, so the filter
 * reads each page's canonical link from the built HTML. No second API request
 * is needed for it.
 */
let outDir = null;
const captureOutDir = {
    name: 'alodium:out-dir',
    hooks: { 'astro:config:done': ({ config }) => (outDir = config.outDir) },
};

function hasOwnCanonical(pageUrl) {
    const path = new URL(pageUrl).pathname;
    try {
        const html = readFileSync(new URL(`.${path.endsWith('/') ? `${path}index.html` : `${path}.html`}`, outDir), 'utf8');
        const canonical = html.match(/<link rel="canonical" href="([^"]+)"/)?.[1];
        return !canonical || new URL(canonical).pathname === path;
    } catch {
        return true; // a page without an HTML file of its own stays in
    }
}

// https://astro.build/config
export default defineConfig({
    // Plain HTML files: content is fetched from the Laravel API at build time only.
    output: 'static',
    // Absolute URLs for the sitemap and canonical links.
    site: env.SITE_URL || 'http://localhost:4321',
    integrations: [captureOutDir, sitemap({ filter: hasOwnCanonical })],
});
