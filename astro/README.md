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
