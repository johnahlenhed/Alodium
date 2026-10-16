// @ts-check
import sitemap from '@astrojs/sitemap';
import { defineConfig } from 'astro/config';
import { loadEnv } from 'vite';

// .env files are not loaded into import.meta.env while the config itself is
// evaluated, so load them here. `astro build` uses production mode
// (.env + .env.production); `npm run build:local` uses development (.env only).
const mode = process.argv.includes('--mode')
    ? process.argv[process.argv.indexOf('--mode') + 1]
    : process.argv.includes('dev') ? 'development' : 'production';
const env = loadEnv(mode, process.cwd(), '');

// https://astro.build/config
export default defineConfig({
    // Plain HTML files: content is fetched from the Laravel API at build time only.
    output: 'static',
    // Absolute URLs for the sitemap and canonical links.
    site: env.SITE_URL || 'http://localhost:4321',
    integrations: [sitemap()],
});
