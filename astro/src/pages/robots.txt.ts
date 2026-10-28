import type { APIRoute } from 'astro';

// robots.txt, written to dist/robots.txt at build time (output: 'static').
// `site` is the config's `site`, i.e. SITE_URL (astro.config.mjs), the same value
// @astrojs/sitemap uses: the sitemap URL is new URL(base, site) plus its file name,
// as the integration builds it, so this always points at the sitemap of this build.
export const GET: APIRoute = ({ site }) => {
    if (!site) throw new Error('robots.txt needs the `site` option (SITE_URL) in astro.config.mjs.');
    const sitemap = new URL('sitemap-index.xml', new URL(import.meta.env.BASE_URL, site));

    return new Response(`User-agent: *\nAllow: /\nSitemap: ${sitemap.href}\n`, {
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
    });
};
