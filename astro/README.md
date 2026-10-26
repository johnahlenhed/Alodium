# Alodium public site (Astro)

Static site generated from the Laravel public content API (`/api/public/*`).
All content is fetched **at build time**; the only runtime JavaScript is the
contact form and the Alodium tracker.

## Setup

```bash
cd astro
cp .env.example .env
npm install --legacy-peer-deps
```

| Variable      | Purpose                                              |
|---------------|------------------------------------------------------|
| `API_URL`     | Laravel public API, no trailing slash                |
| `TRACKER_URL` | URL of `tracker.js` served by Laravel (omit to disable) |
| `SITE_URL`    | Public URL of this site (sitemap, canonical links)   |
| `SITE_NAME`   | Site name in the header, footer, titles and structured data (default Alodium) |

## Build locally

1. Start Laravel from the repo root: `php artisan serve` (localhost:8000)
2. `npm run build:local` — builds into `dist/` using `.env`
3. `npm run preview` — serves `dist/` on http://localhost:4321

`npm run build` is the **production** build: it reads `.env.production`
on top of `.env`, so it needs the real production API to be reachable.

If the API is unreachable the build fails with a clear error. That is
intentional: a site built without content should never be deployed.

`npm run dev` works too, but the static build is what gets deployed.

## Routes

| URL             | Source                          |
|-----------------|---------------------------------|
| `/`             | latest posts                    |
| `/blog`         | all posts                       |
| `/blog/[slug]`  | published posts                 |
| `/[slug]`       | published CMS pages (About, …)  |
| `/contact`      | contact form → `POST /api/public/contact` |

CMS pages whose slug collides with a built-in route (`blog`, `contact`,
`404`, `index`) are skipped with a build warning.

Content changes in the admin panel only appear after a new build.

## Design and theme

| File | Contains |
|------|----------|
| `src/styles/blocks.css` | Blocks, rich text (`.prose`), buttons, the contact form and **all** CSS variables. Works on its own (the editor preview will load it in an iframe). |
| `src/styles/site.css` | Header, footer, page column, post list. |
| `src/lib/theme.js` | Turns the site settings (`brand`, `fontBody`, `fontHeading`, `corners`, `buttons`) into the `:root` variables, `data-button` on `<html>` and the fonts to load. Allow-list only; invalid values fall back to the defaults. |
| `src/lib/fonts.js` | The font options (`inter`, `serif`, `system`) and their `@font-face`/preload markup. The woff2 files and OFL licences are in `public/fonts/`. |
| `src/lib/site.js` | Site name, navigation and footer text. |

Until the site settings feature exists, `BaseLayout` calls `buildTheme()`
without settings, which gives the default design. Only `--color-brand` is a
free choice; every other brand colour is derived from it with `color-mix()`.

Checks (no test framework, no Laravel needed except for `check:build`):

```bash
npm run check          # theme.js/fonts.js checks and WCAG contrast of every colour pair
npm run build:local && npm run check:build   # one <h1>, skip link, aria-current, favicons, hosts, fonts, page column
node scripts/derive-font-fallbacks.mjs        # after replacing a font file
```
