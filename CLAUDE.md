# CLAUDE.md — Alodium

## Project overview
Alodium is a self-hosted content platform built with Laravel + Inertia + React for the admin panel, and Astro for the public-facing website. It includes a privacy-first analytics pipeline, a WordPress XML importer, and a cookieless tracker script.

**Start with `docs/ARCHITECTURE.md`**: the big picture, directory layout, every table, the public API shapes, the block system, theming and settings, roles, jobs, security measures, tests and env variables, plus a list of known gaps. Deployment is in `README.md`, the Astro site in `astro/README.md`.

## Tech stack
- **Backend:** Laravel 13 (PHP ^8.4), MySQL; database queue and cache
- **Admin frontend:** React 18 via Inertia.js v2, Tailwind
- **Public site:** Astro 7, static output (separate project, consumes the Laravel API at build time)
- **Rich text editor:** TipTap
- **Email:** Resend
- **Media:** Spatie Media Library
- **Build tool:** Vite
- **Tests:** PHPUnit (no Pest), plus plain Node check scripts

## Project structure
Short version; the full layout is in `docs/ARCHITECTURE.md` section 2.

app/
├── Blocks/ ← Page block types and their registry
├── Console/Commands/ ← Backups, scheduled publishing, content:sanitize
├── Http/
│ ├── Controllers/ ← Request handling, Inertia responses, public API
│ ├── Middleware/ ← RequireAdmin ("admin"), Inertia shared props, registration switch
│ └── Requests/ ← Form requests (site settings)
├── Jobs/ ← Astro rebuild, import image downloads
├── Models/ ← Eloquent models
├── Observers/ ← Queues a rebuild when published content changes
├── Policies/ ← Who may edit and delete posts and pages
└── Services/ ← Business logic (importer, sanitiser, backups, SSRF-safe downloader)

database/
├── migrations/ ← Database schema
├── seeders/ ← Test data
└── factories/ ← Fake model generation

routes/
├── web.php ← Admin panel routes (auth protected; admin-only group)
├── api.php ← Tracker, public content API, rebuild webhook
└── console.php ← Scheduled commands

resources/
└── js/
├── Pages/ ← React page components (one per route)
├── Components/ ← Reusable React components
└── Layouts/ ← Page layout wrappers

public/
└── tracker.js ← Vanilla JS tracker script

astro/ ← Separate Astro project (public site); astro/scripts holds its checks

tests/ ← Feature/, Unit/, Fakes/ (fake DNS and HTTP), Fixtures/

## Key conventions

### Laravel
- Controllers return `Inertia::render()` for admin panel routes
- API controllers return `response()->json()` for tracker and public endpoints
- All admin routes are wrapped in `auth` middleware in `web.php`; admin-only routes are also in the `admin` group
- API routes for the tracker are in `api.php` with no auth middleware
- Use `Str::slug()` for generating slugs from titles
- Models use `$fillable` not `$guarded`
- Visits and events tables use `$timestamps = false` — only `created_at`, no `updated_at`

### React / Inertia
- Page components live in `resources/js/Pages/`
- Use `useForm` from `@inertiajs/react` for all forms
- Use `Link` from `@inertiajs/react` for navigation — never plain `<a>` tags inside the admin panel
- Props are passed directly from Laravel controllers — no separate API calls needed from page components

### Database
- Always write migrations for schema changes — never modify the database directly
- Foreign keys use `constrained()->onDelete('cascade')` unless there is a specific reason not to
- Nullable columns use `->nullable()` explicitly

### Tracker script
- `public/tracker.js` is vanilla JS with no dependencies
- API endpoints are `/api/collect` (POST), `/api/collect/{id}` (PATCH, needs the visit's token) and `/api/events` (POST)
- All errors are caught silently — the tracker must never break the host site
- UTM parameters are captured then removed from the visible URL

## Commands
```bash
# Start local development
php artisan serve          # Laravel on localhost:8000
npm run dev                # Vite on localhost:5173
npm run build              # Build the admin assets

# Database
php artisan migrate        # Run migrations
php artisan migrate:fresh --seed  # Reset and seed
php artisan app:configure-env       # Fill .env secrets (generated) and credentials (asked); keeps set values, caches config once
php artisan user:set-password EMAIL  # Create an admin or set a password (hidden prompt or --generate); sends no email

# Testing
php artisan test           # Run the PHPUnit tests (in-memory SQLite, no network)
node resources/js/Components/Blocks/blocksReducer.check.mjs   # Block editor list operations
node resources/js/utils/importRun.check.mjs                    # Import run page state
node resources/js/theme.check.mjs                              # Admin colour tokens, light and dark: contrast, no undefined colours

# Background work
php artisan queue:work     # Astro rebuilds, import image downloads, media conversions
php artisan schedule:work  # Locally: runs content:publish-scheduled, db:backup, media:backup-remote
php artisan content:sanitize --dry-run   # How many stored posts/pages the sanitiser would change

# Public site (in astro/, Laravel must be running)
npm run build:local        # Build with astro/.env into astro/dist
npm run check              # theme, settings and colour contrast checks (no server needed)
npm run check:build        # Checks on the built HTML, incl. against the live /api/public/site
```

## GeoIP (visitor country lookup)
Country is derived from the visitor IP with `stevebauman/location` using the **offline MaxMind GeoLite2-Country** database, so visitor IPs are never sent to a third party (and the IP itself is never stored — only the country code).
- The `.mmdb` file is **not in the repo** (too large, and MaxMind's licence forbids redistributing it). It must be downloaded separately and placed at `storage/app/geoip/GeoLite2-Country.mmdb` (`storage/app/` is gitignored).
- Get it with a free MaxMind account: set `MAXMIND_LICENSE_KEY` in `.env`, then `mkdir -p storage/app/geoip && php artisan location:update`, or download `GeoLite2-Country` manually from the MaxMind account portal and copy the `.mmdb` there.
- If the file is missing, lookups return `null` and `visits.country` stays empty; tracking still works.
- `config/location.php` has `fallbacks` deliberately empty — the package's other drivers are third-party HTTP APIs and would leak IPs if the local lookup failed. Do not re-add them.
- Tracker conventions above are unchanged; geolocation happens server-side in `TrackingController`.

## npm install note
Due to Vite 8 and @vitejs/plugin-react peer dependency conflict, always use (in the root and in `astro/`):
```bash
npm install --legacy-peer-deps
```
`composer setup` passes the flag too.

## Environment
Copy `.env.example` to `.env` (and `astro/.env.example` to `astro/.env`) and configure:
- `DB_*` — MySQL connection
- `RESEND_API_KEY` — transactional email
- `APP_URL` — the Laravel origin (also used to make `/storage/` image paths absolute in the API)
- `VITE_APP_NAME` — admin page titles

Every project-specific variable, with its purpose, is listed in `docs/ARCHITECTURE.md` section 11.

## What's been built (proof of concept reference)
A proof of concept exists at a separate repo. The following patterns are established and can be reused:
- Tracker script logic and API endpoints
- Analytics dashboard with Recharts
- Basic CRUD patterns for posts and pages
- Database schema for visits and events

## Examensarbete context
This project is being built as an examensarbete (degree project) at a Swedish web development program. AI usage is deliberately documented as part of the methodology. When generating code, explain non-obvious decisions so they can be understood and documented.