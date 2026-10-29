# Alodium architecture

How the parts of Alodium fit together, written from the code in this
repository. Where something could not be confirmed in the code it says
"not found". Deployment steps are in the root `README.md`; the Astro site has
its own `astro/README.md`.

## Contents

1. [The big picture](#1-the-big-picture)
2. [Directory layout](#2-directory-layout)
3. [Database tables](#3-database-tables)
4. [Public API](#4-public-api)
5. [Blocks](#5-blocks)
6. [Theming and site settings](#6-theming-and-site-settings)
7. [Roles and permissions](#7-roles-and-permissions)
8. [Jobs and scheduled commands](#8-jobs-and-scheduled-commands)
9. [Security measures](#9-security-measures)
10. [Tests and checks](#10-tests-and-checks)
11. [Environment variables](#11-environment-variables)
12. [Known gaps](#12-known-gaps)

## 1. The big picture

| Part | What it is |
|---|---|
| Admin panel | Laravel 13 + Inertia v2 + React 18 (`resources/js`), Tailwind, built with Vite. Session login; `/` redirects to the dashboard (guests to the login). |
| Public content API | Laravel, `routes/api.php` under `/api/public/*`. Read-only JSON plus the contact form. |
| Public site | Astro 7 in `astro/`, `output: 'static'`. All content is fetched from the API **at build time**; the only runtime JavaScript is the contact form and the tracker. |
| Queue worker | `php artisan queue:work` (database queue). Runs Astro rebuilds, image downloads after an import, and queued media conversions. |
| Scheduler | `php artisan schedule:run` from cron (`routes/console.php`). |
| Tracker | `public/tracker.js`, vanilla JS, cookie-free. Posts visits and events to `/api/collect` and `/api/events`. |

**From publish to rebuild.** Posts and pages carry
`#[ObservedBy(PublicContentObserver::class)]`. Creating, updating or deleting
an item that is (or was) `published` dispatches `RebuildAstroJob`. That job is
`ShouldBeUniqueUntilProcessing` (many dispatches while one waits = one build)
and uses `WithoutOverlapping('astro-rebuild')` (two builds never run at once).
It runs the script in `ASTRO_REBUILD_SCRIPT` (`deploy/rebuild-astro.sh`), which
runs `npm run build` in `astro/`, copies the result to the web root only when
the build succeeded, and writes start/complete/FAILED lines to
`storage/logs/astro-rebuild.log` (read by the dashboard). Other triggers: the
Settings page (only when the public payload changed), the last finished image
job of an import, and `POST /api/webhook/rebuild`. Scheduled items are
published by `content:publish-scheduled`, which saves them as models so the
observer fires.

## 2. Directory layout

```
app/
  Blocks/            BlockRegistry + BlockType, one class per block type in Types/
  Console/Commands/  db:backup, media:backup-remote, content:publish-scheduled,
                     content:sanitize, db:backup-test-remote
  Http/Controllers/  admin pages (Inertia) and API controllers; Auth/ is Breeze
  Http/Middleware/   RequireAdmin ("admin" alias), HandleInertiaRequests, EnsureRegistrationIsEnabled
  Http/Requests/     UpdateSiteSettingsRequest, ProfileUpdateRequest, Auth/LoginRequest
  Jobs/              RebuildAstroJob, DownloadContentImages
  Mail/              ContactSubmissionReceived, UserInvitation
  Models/            Eloquent models; Concerns/RegistersImageConversions
  Observers/         PublicContentObserver
  Policies/          ContentPolicy (shared), PostPolicy, PagePolicy
  Rules/             BrandColor, ImageUpload, MediaUrl, SafeLink
  Services/          importer, sanitisers (HTML, SVG), backups, statistics, settings payload,
                     SafeImageDownloader + ImageDownload/ (resolver, curl client)
  Support/           small pure helpers (ColorContrast, ContentImages, Shortcodes, ThemeOptions, ...)
  Traits/            slugs, publish status, revisions, sanitising on save, list rows
resources/js/
  Pages/             one component per Inertia page (Posts, Pages, Media, Submissions,
                     Analytics, Import, Users, Settings, Profile, Auth, Dashboard)
  Components/        shared UI; Blocks/ is the block editor
  Layouts/           AuthenticatedLayout (admin nav), GuestLayout
  utils/             publishing.js (time zones), contrast.js (imports astro/src/lib/color.js),
                     importRun.js (import run page: polling state, reasons, formatting)
astro/
  src/pages/         index, blog/index, blog/[slug], [slug] (CMS pages), contact, 404,
                     robots.txt.ts (robots.txt with the sitemap URL from SITE_URL)
  src/layouts/       BaseLayout (head, theme, header, footer, tracker)
  src/components/    SiteHeader, SiteFooter, CmsPage, PostList, ContactForm, BrandMark, blocks/
  src/lib/           api.js (fetch + getSite), settings.js, site.js (defaults), theme.js,
                     fonts.js, color.js, blocks.js (safeLink/safeImage), jsonld.js
  src/styles/        blocks.css (all CSS variables; works standalone), site.css (layout)
  public/fonts/      self-hosted woff2 files and their OFL licences
  scripts/           node check scripts (section 10)
tests/
  Feature/           HTTP and integration tests (Concerns/ holds shared helpers)
  Unit/              pure tests (PublicAddressTest, AddDomainScriptTest)
  Fakes/             fake DNS and HTTP for the image downloader
  Fixtures/          brand-colours.json and the script that generates it
deploy/              rebuild-astro.sh; add-domain.sh (README sections 4-6 on a
                     new server) and check-add-domain.sh
docs/testdata/       ssrf-smoke.xml (import by hand after a deploy, section 9)
```

## 3. Database tables

| Table | Purpose and key columns |
|---|---|
| `users` | Admin accounts. `role` enum `admin`/`editor` (column default `admin`). |
| `posts` | Blog posts. `author_id`, `title`, `slug` (unique), `content` (sanitised HTML), `meta_title`, `meta_description`, `og_image`, `status` (`draft`/`published`), `published_at`, `publish_at` (scheduled time). |
| `pages` | CMS pages. Same as posts plus `blocks` (JSON `{version, blocks}`; null = legacy HTML page). `show_in_nav` and `nav_order` are no longer used (copied to `navigation_items`). |
| `navigation_items` | The main navigation after Home, in `sort_order`. Each entry has either `page_id` (cascade on delete; `label` null = the page's title, hidden while the page isn't on the public site) or `url` (a path on the site such as `/blog`, or an http(s) address; `label` required). |
| `revisions` | Snapshots before a published post/page is edited. Polymorphic `model`, `title`, `content`, `blocks`. Newest 20 kept. |
| `media` | Spatie Media Library. Polymorphic owner, `collection_name` (`library` on users, `featured` on posts/pages, `logo` on site settings), `custom_properties` (imported images: `source_url`, `source_hash`; logo: `logo_width`, `logo_height`). |
| `site_settings` | One row (id 1): `site_name`, `site_description`, `footer_text`, `show_site_name`, `brand_color`, `font_body`, `font_heading`, `corners`, `buttons`, `front_page_id` (null on delete). `show_blog_in_nav` and `show_contact_in_nav` are no longer used: Blog and Contact are `navigation_items` entries. No column defaults: defaults live in `SiteSetting::defaults()`. |
| `submissions` | Contact form messages: `name`, `email`, `message`, `read`. |
| `visits` | One page view: `url`, `referrer`, `utm_*`, `device_type`, `screen_width`, `language`, `country`, `duration`, `scroll_depth`, `token`, `created_at` (indexed). No IP column. |
| `events` | Tracker events: `visit_id`, `type`, `payload` (JSON). |
| `import_runs` | One WordPress import: `status` (importing/downloading/done/failed), `download_images`, `imported_posts`/`imported_pages`, `report` (JSON: skipped items, `warnings` per imported item with title, type, slug and message, errors, item types found), item and image counters (featured images included), `bytes_downloaded`, `failures` (JSON, max 200), `skipped_reasons` (JSON, reason => count for every skipped image), `allowed_hosts` (JSON). |
| `jobs`, `job_batches`, `failed_jobs` | Laravel database queue. |
| `cache`, `cache_locks` | Database cache (also used for job uniqueness and import image locks). |
| `sessions`, `password_reset_tokens` | Laravel defaults. |

## 4. Public API

All under `/api`. Responses are JSON; `/api/*` errors are rendered as JSON.

| Endpoint | Response |
|---|---|
| `GET /public/posts` | List of published posts: `id, title, slug, excerpt (160 chars), meta_title, meta_description, published_at, updated_at, author`. |
| `GET /public/posts/{slug}` | One post: the list fields without `excerpt`, plus `content`, `og_image`, `og_image_width`, `og_image_height`. 404 `{message}` for drafts and unknown slugs. |
| `GET /public/pages` | Like posts, plus `blocks` (array, or null for legacy pages). |
| `GET /public/pages/{slug}` | Like a post, plus `blocks`. A block page returns `content: ""`. |
| `GET /public/site` | `{name, description, footer_text, show_site_name, theme: {brand, fontBody, fontHeading, corners, buttons}, logo: {url, width, height}\|null, navigation: [{label, href}], front_page: {slug}\|null}`. `navigation` is Home, then the `navigation_items` entries; `href` is a path on the site or an http(s) address. |
| `POST /public/contact` | Body `name, email, message, website` (honeypot). `{success: true}` or 422 errors. Sends a notification mail. |
| `POST /collect` | Starts a visit. Returns `{visit_id, token}`. |
| `PATCH /collect/{visit}` | Duration/scroll depth; needs the visit's `token`. `{success: true}`. |
| `POST /events` | An event for a visit; needs the token. `{success: true}`. |
| `POST /webhook/rebuild` | Header `X-Webhook-Secret`. 202 `{message}` or 401. |

"Published" means `status = published` and `published_at` null or in the
past. In `content` and block HTML, `img src="/storage/..."` is made absolute
with `APP_URL` at response time. `og_image` falls back to: block images
(hero, image block, first column image), the featured image, the first image
in the content. Admin-only JSON in `routes/web.php`: `GET /api/media` (media
picker, session auth) and `GET /import/runs/{run}/status` (`status`,
`download_images`, counters, `bytes_downloaded`, `skipped_by_reason`, the first
50 `failures` and `failures_recorded`), which the run page `/import/runs/{run}`
polls. The old `/import/result` redirects to the latest run.

## 5. Blocks

Block pages store `pages.blocks` as `{version: 1, blocks: [{id, type, data}]}`.
Types: `hero`, `text`, `image`, `columns`, `button`, `contact_form`. Max 50.

| Side | Registry | Per type |
|---|---|---|
| Laravel | `app/Blocks/BlockRegistry.php` (`TYPES`) | a `BlockType` class in `app/Blocks/Types/` with `name()`, `rules()`, `sanitize()`, optional `attributes()` and `variants()` |
| Admin | `resources/js/Components/Blocks/blockTypes.js` (`BLOCK_TYPES`) | label, icon, `defaultData`, an `*Editor.jsx`, `fields`, `summary` |
| Astro | `astro/src/components/blocks/registry.js` (`BLOCK_TYPES`) | an `.astro` component and a `check(data)` that skips the block with a build warning when it returns a reason |

To add a type: one class plus one line in each of the three registries, and
matching CSS in `astro/src/styles/blocks.css`. The registry validates and
normalises on save; HTML fields go through `ContentSanitizer`. Astro checks
again at render time (`safeLink`, `safeImage`). A hero as the first rendered
block provides the page's `<h1>` (`CmsPage.astro`).

**Backgrounds (`data.variant`).** `BlockType::VARIANTS` is `default`, `light`
(the brand tint), `brand` and `dark` (a near-black shade of the brand). The hero
offers `default` (the brand colour), `light` and `dark`; text and columns offer
all four; the other types none. A block missing the field is `default`. For a
new type to offer them:

1. Laravel: return the subset from `variants()`, add `'variant' =>
   $this->variantRule()` to `rules()` and `'variant' => $this->variant($data)`
   to `sanitize()`.
2. Admin: `variant: 'default'` in `defaultData` and `fields`, and a
   `VariantField` (`fields.jsx`) in the editor.
3. Astro: `variantClass(data.variant, allowed)` from `lib/blocks.js`, which
   only lets known names through. Text and columns also get `block--panel`:
   the background reaches into the gutter, and the text stays in line with the
   blocks around it.
4. CSS: the `.block--light`, `.block--brand` and `.block--dark` rules only
   redefine colour variables (`--color-text`, `--color-link`, `--color-surface`,
   `--color-focus`, ...), so rich text inside follows. A new colour pair needs
   an entry in `pairs()` in `astro/scripts/check-contrast.mjs`: the script reads
   the colours from `blocks.css`, but checks only the pairs listed there.

## 6. Theming and site settings

1. An admin edits `/settings` (`SiteSettingsController`, `UpdateSiteSettingsRequest`).
   The allowed values come from `App\Support\ThemeOptions` (fonts `inter`,
   `serif`, `system`; corners `sharp`, `soft`, `round`; buttons `solid`,
   `outline`). Brand colour: `#rrggbb` with at least 4.5:1 contrast against
   white (`App\Rules\BrandColor`, `App\Support\ColorContrast`).
2. The navigation entries replace the `navigation_items` rows on each save
   (at most 14, up and down buttons in `NavigationEditor.jsx`; the pages
   themselves are not touched, so no `updated_at` change or revision). Astro
   gives `aria-current` to paths on the site only (`currentState()`), so
   `/blog` and `/contact` keep it and an http(s) link never gets it. The logo
   is the `logo` collection on `SiteSetting`, with a PNG conversion at most
   128 px high.
3. A rebuild is queued only if `PublicSiteSettings::payload()` differs before
   and after the save.
4. Astro: `getSite()` in `api.js` fetches `/public/site` once per build. A
   failed request or a non-object response fails the build; single invalid
   fields fall back to `src/lib/site.js` defaults (`src/lib/settings.js`).
5. `BaseLayout` calls `buildTheme(settings.theme)` (`src/lib/theme.js`), which
   writes a `:root` rule with `--color-brand`, `--font-body`, `--font-heading`,
   `--radius`, sets `data-button` on `<html>`, and lists the font files to
   preload. Every other colour is derived with `color-mix()` in `blocks.css`.

The PHP and JS rules are kept in sync by tests: `SiteThemeSyncTest` loads
`theme.js`/`fonts.js` with Node; `BrandColorTest` reads a fixture generated
from `color.js`.

## 7. Roles and permissions

| Action | Admin | Editor |
|---|---|---|
| Create posts and pages, edit own items and anyone's published items | yes | yes |
| Edit other authors' drafts and scheduled items | yes | no (`ContentPolicy::update`); the posts and pages lists show them by title, status, dates and author only (`PresentsIndexRows`) |
| Delete posts, pages, media, submissions | yes | no (`admin` route group) |
| Media library upload and list, read submissions | yes | yes |
| Analytics, WordPress import, users, site settings | yes | no |
| Dashboard visitor, traffic and system data | yes | no (`DashboardController::build`) |

Self-registered accounts are always editors; registration is closed by default
in production (`AUTH_REGISTRATION`). Admins create users with an invitation
mail; they cannot change their own role or delete themselves there.

## 8. Jobs and scheduled commands

| Job / command | When | What |
|---|---|---|
| `RebuildAstroJob` | see section 1 | runs the rebuild script; timeout 600 s, retried once, gives up after an hour |
| `DownloadContentImages` | per imported item with images (in the content or as its featured image), when the import runs with `download_images` | attaches the featured image first (a copy when the same image is already in the media library), then downloads content images and rewrites `src` to `/storage/...`; never saves the model (no revision, no per-item rebuild); requeues itself after `import.job_time_budget`; the last item marks the run done and queues one rebuild |
| Spatie conversions | on upload | `thumb` and `og` during the request; `medium` and `webp` queued |
| `content:publish-scheduled` | every minute | publishes due drafts with `publish_at` |
| `db:backup` | daily 02:00 | `mysqldump` to `storage/app/backups`, keeps 30; optional encrypted off-server copy |
| `media:backup-remote` | daily 02:30 | copies new/changed files from `storage/app/public` off-server (when configured) |
| `location:update` (package) | monthly, 1st at 03:00, only with `MAXMIND_LICENSE_KEY` set | downloads the GeoLite2 country database to `storage/app/geoip`; the dashboard's Site status (`SiteStatus::geoip()`) warns when the file is missing, unreadable or more than 45 days old |
| `content:sanitize` | manually | re-sanitises stored content (`--dry-run` reports only) |
| `db:backup-test-remote` | manually | encrypts, uploads, reads back and deletes a small test file to check the off-server setup |

The README requires the worker to run with `--timeout=660` and
`DB_QUEUE_RETRY_AFTER` above it in production.

## 9. Security measures

- **HTML sanitising**: `ContentSanitizer` (Symfony HtmlSanitizer) is the one
  allowlist for stored rich text; `style` is limited to `text-align`
  (`TextAlignStyleSanitizer`), and protocol-relative `<img src="//host">` is
  removed (`ImageSourceSanitizer`; the importer turns `//` into `https://` first). Applied on save (`SanitizesContent`), by the
  importer, and to block HTML.
- **Link and image checks**: `SafeLink` and `MediaUrl` rules on the server;
  `safeLink`/`safeImage` again in Astro.
- **SSRF guard for imports**: `SafeImageDownloader` allows http(s) on ports
  80/443 only, only hosts from the export, rejects any non-public resolved
  address, pins the connection with `CURLOPT_RESOLVE`, checks every redirect
  (max 3), caps bytes while streaming, and accepts only JPEG/PNG/WebP/GIF by
  content within a pixel limit. Limits in `config/import.php`.
  The upload request itself downloads nothing; featured images go through the
  same jobs and checks.
- **SSRF smoke test after a deploy**: import `docs/testdata/ssrf-smoke.xml`
  with "Download images from the old site" checked. Its attachments put
  `169.254.169.254`, `127.0.0.1`, `::1` and `2130706433` on the allowlist, the
  way a hostile export would. A correct result: 1 post imported (a draft
  titled "SSRF smoke test (delete me)"), 5 images found (4 in the content, 1
  featured), 0 downloaded, 0 reused, 5 skipped, all "Address not allowed
  (private or internal)", 0 MB. Any download, or another reason, is a failure
  of the guard. Delete the draft afterwards (a second import would skip it as
  a duplicate and find no images). `SsrfSmokeTest` runs the same file.
- **XML**: the importer parses with `LIBXML_NONET` and without entity substitution.
- **Uploads**: the logo is checked by content type and dimensions; the media
  library accepts jpg, png, gif, webp and svg up to 10 MB (`App\Rules\ImageUpload`).
  The type comes from the file's bytes, and the uploaded name must end in an
  extension of that type ("x.html" holding a GIF is refused). The stored name is
  a slug of the uploaded one with the type's own extension, so a file in
  `/storage` is always served with an image content type. SVGs are cleaned by
  `App\Services\SvgSanitizer` (enshrined/svg-sanitize: an allowlist of elements
  and attributes, no remote references, no DOCTYPE) before they are stored.
  Files uploaded before this check keep their original names.
- **Tracker tokens**: each visit gets a random 40-character token, returned
  only on creation and compared with `hash_equals`. IPs are never stored;
  the country comes from an offline MaxMind database.
- **Webhook**: `hash_equals` on `X-Webhook-Secret`; an empty secret disables it.
- **Rate limits**: tracker 120/min, contact 5/min, webhook 10/min per IP. The
  public GET endpoints share 60/min per IP (`public-content` limiter in
  `AppServiceProvider`), except for requests from the server itself (loopback,
  or `REMOTE_ADDR` equal to `SERVER_ADDR`): the Astro build makes one request
  per published post and page plus five, all at once, so it is not limited.
- **Role checks**: `admin` middleware, policies, and the role split in the
  dashboard. CORS allows any origin for `api/*` only.
- **Output**: JSON-LD is serialised with `<`, `>` and `&` escaped; Astro text
  uses normal escaping; theme values pass allow-lists on both sides.
- **Regexes** use `\z`, not `$`.

## 10. Tests and checks

- `php artisan test` runs PHPUnit (`tests/Feature`, `tests/Unit`) on in-memory
  SQLite with the sync queue (`phpunit.xml`). The tests need no network: the
  image downloader uses `tests/Fakes`.
- `node resources/js/Components/Blocks/blocksReducer.check.mjs` checks the
  pure block-list functions of the editor.
- `node resources/js/utils/importRun.check.mjs` checks the import run page's
  state (poll timing and back-off, stall detection, milestones, reason
  mapping and grouping, formatting).
- `node tests/Fixtures/generate-brand-colours.mjs` regenerates the colour fixture.
- `deploy/check-add-domain.sh` checks that `deploy/add-domain.sh` writes and runs
  exactly what README sections 4 to 6 describe: the env block, the plain-HTTP
  Nginx config, the `MAXMIND_LICENSE_KEY` line and the GeoIP download commands. `php artisan test` runs it (`tests/Unit/AddDomainScriptTest.php`).
- In `astro/` (no test framework):

| Script | npm command | Checks |
|---|---|---|
| `check-theme.mjs` | `npm run check` | theme.js/fonts.js values, hostile input, font combinations |
| `check-settings.mjs` | `npm run check` | `normalizeSite()` validation |
| `check-contrast.mjs` | `npm run check` | WCAG contrast of the colour pairs listed in its `pairs()` (block variants included), with the colours read from `blocks.css` |
| `check-build.mjs` | `npm run check:build` | one `<h1>`, skip link, aria-current, favicons, external hosts, fonts |
| `check-layout.mjs` | `npm run check:build` | the page column rule and overflow safety in the built CSS |
| `check-site.mjs` | `npm run check:build` | built HTML against the live `/public/site` response (needs Laravel) |
| `derive-font-fallbacks.mjs` | (manual) | fallback font metrics in `fonts.js` |

## 11. Environment variables

Laravel (`.env`), project-specific ones; standard Laravel and package
variables (`APP_*`, `DB_*`, `MAIL_*`, `SESSION_*`, `CACHE_STORE`,
`QUEUE_CONNECTION`, `LOG_*`, ...) have their usual meaning.

| Variable | Purpose |
|---|---|
| `APP_URL` | Laravel origin; also makes `/storage/` image paths absolute in the API |
| `PUBLIC_SITE_URL` | public site address: the dashboard's links, and its host (with or without `www.`) marks referrers as navigation within the site ("Your own site" in Traffic sources, left out of Top referrers on `/analytics`). Unset: nothing counts as internal |
| `ASTRO_REBUILD_SCRIPT` | path to the rebuild script; empty skips rebuilds |
| `WEBHOOK_SECRET` | secret for `POST /api/webhook/rebuild`; empty disables it |
| `DB_QUEUE_RETRY_AFTER` | must exceed the worker timeout (660) |
| `AUTH_REGISTRATION` | allow public registration (default: off in production) |
| `CONTACT_NOTIFY_EMAIL` | recipient of contact form mails (falls back to the from address) |
| `RESEND_API_KEY` | Resend mail transport |
| `MAXMIND_LICENSE_KEY` | downloading the GeoLite2 country database (README step 6, then monthly by the scheduler); without the database visits get no country |
| `SENTRY_LARAVEL_DSN`, `SENTRY_TRACES_SAMPLE_RATE` | error reporting (nothing without a DSN) |
| `DB_BACKUP_PATH`, `DB_BACKUP_KEEP`, `DB_BACKUP_REMOTE_KEEP` | local backup folder and how many backups to keep |
| `BACKUP_S3_KEY`, `_SECRET`, `_REGION`, `_BUCKET`, `_ENDPOINT`, `_USE_PATH_STYLE` | off-server backup bucket |
| `BACKUP_ENCRYPTION_PASSPHRASE` | encrypts off-server database copies |
| `MEDIA_DISK` | disk for Spatie media (default `public`) |
| `QUEUE_CONVERSIONS_BY_DEFAULT` | queue the larger media conversions |
| `IMPORT_MAX_IMAGE_BYTES`, `IMPORT_MAX_MEGAPIXELS` | per-image download limits |
| `IMPORT_MAX_IMAGES_PER_ITEM`, `IMPORT_MAX_IMAGES_PER_RUN`, `IMPORT_MAX_TOTAL_BYTES` | per-item and per-run limits |
| `IMPORT_MIN_FREE_DISK_BYTES` | stop downloading below this free space |
| `IMPORT_JOB_TIME_BUDGET` | seconds before an image job requeues itself |
| `VITE_APP_NAME` | admin page titles |

Astro (`astro/.env`, `astro/.env.production`):

| Variable | Purpose |
|---|---|
| `API_URL` | Laravel public API, no trailing slash; required for the build |
| `TRACKER_URL` | URL of `tracker.js`; omit to build without the tracker |
| `SITE_URL` | public site address for the sitemap, robots.txt and canonical links |

## 12. Known gaps

- **Sanitiser and relative images**: `allowRelativeMedias()` still keeps
  relative `<img src>` values other than `/storage/...` (e.g. `../x.jpg`);
  only protocol-relative `//host` sources are removed.
- **Import run page in a browser**: the polling, the focus move, the progress
  bar, the stall hint and the layout are covered by the pure module's node
  check and the PHP tests, not by browser tests.
- **`download_images` on the server** still defaults to off when the field is
  missing; only the upload form checks it by default.
- **Runs from before the run page** (no `report`, `imported_*` or
  `skipped_reasons` columns yet) show zero imported items and no grouping.
- **Failure details**: 200 are kept per run and 50 shown; the counts per
  reason cover every skipped image.
- **Shared featured images** are copied per post or page, so the file is
  stored once per item (a featured image is deleted with its item).
- **Old links in imported content**: links to WordPress attachment pages and
  non-image files (PDF, MP3) keep pointing at the old site.
- **Shortcodes in imports**: only WordPress core's are converted
  (`App\Support\Shortcodes`: gallery, caption, wp_caption, audio, video,
  playlist, embed). A gallery becomes its images in one paragraph (no grid); a
  caption becomes the image plus an emphasised caption paragraph (alignment as
  `text-align` only, no text wrapping); audio, video, playlists and embeds
  become links, and the files stay on the old site. Each of these, and every
  plugin shortcode (left as text), is listed in the run's warnings. Bare URLs
  on a line of their own (WordPress auto-embeds) stay plain text. As in
  WordPress, an unclosed shortcode followed later by a closing tag of the same
  name takes everything in between as its content.
- **Encoding repair in imports**: before parsing, control characters XML does
  not allow are removed and, in a UTF-8 file, invalid bytes are read as
  Windows-1252 (each with a warning naming the item). A file in another
  single-byte encoding but labelled UTF-8 gets the Windows-1252 guess. An item
  libxml still reports a parse problem in is imported as a draft, with a warning.
- **Imported images** point at the original file; the block picker stores the
  `medium` conversion instead.
- **Editor preview**: `blocks.css` is written to work standalone for an editor
  preview in an iframe; that preview was not found.
- **Favicon**: the public site always uses the Alodium favicon, whatever logo is uploaded.
- **Front page in the navigation**: the server returns it; only Astro removes it.
- **Old navigation columns**: `pages.show_in_nav`, `pages.nav_order`,
  `site_settings.show_blog_in_nav` and `show_contact_in_nav` are still in the
  database, unused, until a later migration drops them (after the
  `navigation_items` backfill has been checked on production).
- **Unpublished navigation pages**: the Settings page doesn't list an entry whose
  page isn't on the public site, so saving the settings drops it (as the old
  `show_in_nav` flag was cleared).
- **Reserved slugs** (`blog`, `contact`, `404`, `index`) are listed in both
  `Page::RESERVED_SLUGS` and `astro/src/pages/[slug].astro` (kept equal by a test).
- **Role default**: `users.role` defaults to `admin` at the database level;
  every code path that creates users sets the role explicitly.
- **Default test**: `tests/Unit/ExampleTest.php` is still the framework
  example (it asserts `true`).
- **Build rate-limit exemption** assumes the build reaches Laravel directly.
  Behind a proxy such as Cloudflare's orange cloud, requests would come from the
  proxy's IPs: the build would be limited to 60/min and fail on a larger site.
