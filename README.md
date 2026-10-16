# Alodium

A self-hosted content platform for freelance web developers. Combines a modern CMS, privacy-first analytics, a WordPress content importer, and an Astro-powered public site giving clients full ownership of their data, their content, and their corner of the web. No Google. No plugins. No platform lock-in.

## Stack

- **Admin panel:** Laravel + Inertia.js + React
- **Public site:** Astro
- **Database:** MySQL
- **Email:** Resend
- **Media:** Spatie Media Library

## Requirements

- PHP 8.4+
- Composer
- Node.js 18+
- MySQL

### PHP extensions

- `php8.4-gd`: image processing and WebP conversion
- `php8.4-xml`: WordPress XML import
- `php8.4-mysql`
- `php8.4-mbstring`
- `php8.4-curl`

On Debian/Ubuntu:

```bash
sudo apt install php-gd php-xml php-mysql php-mbstring php-curl
```

### PHP upload limits

Media uploads (up to 10 MB) and WordPress XML imports (up to 20 MB) need higher limits than PHP's defaults. In `php.ini`, set at least:

```ini
upload_max_filesize = 25M
post_max_size = 30M
```

Run `php --ini` to find which `php.ini` is loaded. The CLI (`php artisan serve`) and PHP-FPM use separate files, so update the one your server uses and restart it.

## Installation

```bash
git clone https://github.com/yourusername/alodium.git
cd alodium
composer install
npm install --legacy-peer-deps
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Configure your `.env` with database credentials and Resend API key, then:

```bash
php artisan serve
npm run dev
```

Visit `http://localhost:8000`.

## GeoIP database (analytics country lookup)

Alodium resolves each visitor's country from their IP using the **MaxMind GeoLite2-Country** database, read locally on your server. No visitor IP is ever sent to a third-party service, and IPs are never stored — only the resulting country code.

The database file is **not included in the repository**: it is too large to commit and MaxMind's licence does not allow redistributing it. Download it once and place it at:

```
storage/app/geoip/GeoLite2-Country.mmdb
```

1. Create a free account at [maxmind.com](https://www.maxmind.com/en/geolite2/signup) and generate a license key.
2. Add it to `.env`: `MAXMIND_LICENSE_KEY=your-key`
3. Download it:

```bash
mkdir -p storage/app/geoip
php artisan location:update
```

Or download the `GeoLite2 Country` (`.mmdb`) file manually from your MaxMind account and copy it to the path above. MaxMind updates the database regularly, so re-run `php artisan location:update` now and then (e.g. monthly via a scheduled task).

Without the file everything still works; visits are simply recorded without a country.

This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com).

## Deployment

A step-by-step guide to deploying Alodium on a single Ubuntu server. One server runs both parts: the Laravel admin panel and API on port 80, and the static Astro public site on port 8080.

Throughout this guide, replace `SERVER` with your server's IP address or domain. All commands are run as root (or with `sudo`) unless stated otherwise.

### Server requirements

| Component | Version |
|---|---|
| OS | Ubuntu 24.04 LTS |
| PHP | 8.4, with `gd`, `xml`, `mysql`, `mbstring`, `curl`, `zip`, `bcmath`, `intl` (tokenizer is included in `php8.4-common`) |
| Database | MySQL 8.0 |
| Web server | Nginx |
| Node.js | 22.x, version **22.22 or newer** (required by Astro) |
| Composer | 2.x |
| Process manager | Supervisor |
| Memory | A 2 GB swap file is strongly recommended on 1 GB RAM servers. Astro builds run out of memory without it |

### 1. Server setup

```bash
apt update && apt upgrade -y

# Nginx
apt install -y nginx

# PHP 8.4. The Ubuntu 24.04 repositories only have 8.3, so use the ondrej/php PPA.
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update
apt install -y php8.4-fpm php8.4-cli php8.4-gd php8.4-xml php8.4-mysql \
  php8.4-mbstring php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
```

Use the versioned `php8.4-*` package names here. With the PPA enabled, the generic names (`php-gd`, …) install whatever PHP version the PPA considers newest.

Raise the upload limits for PHP-FPM in `/etc/php/8.4/fpm/php.ini`. This is a separate file from the CLI `php.ini`.

```ini
upload_max_filesize = 25M
post_max_size = 30M
```

```bash
systemctl restart php8.4-fpm

# MySQL
apt install -y mysql-server
mysql_secure_installation
```

Create the database and a dedicated user:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE alodium CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'alodium'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON alodium.* TO 'alodium'@'localhost';
FLUSH PRIVILEGES;
```

```bash
# Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Node.js 22.x
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs
node -v   # must be v22.22 or newer

# Supervisor
apt install -y supervisor
```

### 2. Swap file

Do this before the first Astro build on servers with 1 GB of RAM.

```bash
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
free -h   # verify the swap is active
```

### 3. Application setup

```bash
git clone https://github.com/johnahlenhed/Alodium.git /var/www/alodium
cd /var/www/alodium

composer install --no-dev --optimize-autoloader
```

`storage/app/` and `storage/framework/` are not in git, so create them manually:

```bash
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
  storage/framework/views storage/logs

cp .env.example .env
php artisan key:generate
```

Edit `.env` for production. At minimum:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=http://SERVER

DB_CONNECTION=mysql
DB_DATABASE=alodium
DB_USERNAME=alodium
DB_PASSWORD=a-strong-password

MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=hello@alodium.dev

QUEUE_CONNECTION=database
```

Then:

```bash
php artisan migrate --force
php artisan storage:link
```

Create your admin account:

```bash
php artisan tinker
> App\Models\User::create(['name' => 'Your Name', 'email' => 'you@example.com', 'password' => 'a-strong-password']);
```

> **Do not run `php artisan db:seed` in production.** The seeder is blocked when APP_ENV=production and exits with a warning. In other environments it creates a dev login (`john@alodium.dev` / `password`) and 200 fake analytics visits for testing. Create the first production admin user with `php artisan tinker` instead.

> **Registration is disabled in production. Create the first admin user with: `php artisan tinker`** (see above). With `APP_ENV=production`, `/register` redirects to the login page, submitting the form returns 403, and the Register link is hidden, because every account has full admin access. To allow sign-ups anyway, set `AUTH_REGISTRATION=true`, then run `php artisan config:cache`.

Set file ownership so PHP-FPM and the queue worker (both `www-data`) can write to storage:

```bash
chown -R www-data:www-data /var/www/alodium
```

Build the admin panel assets **on your local machine**. Vite runs out of memory on a 1 GB server. Then copy them to the server:

```bash
# locally, in the project root
npm install --legacy-peer-deps
npm run build
ssh root@SERVER "mkdir -p /var/www/alodium/public/build"
scp -r public/build/* root@SERVER:/var/www/alodium/public/build/
```

Use `public/build/*`, not `public/build`. `scp -r public/build` would create `public/build/build/` on the server.

For visitor countries in analytics, install the MaxMind database as described in [GeoIP database](#geoip-database-analytics-country-lookup).

### 4. Astro public site

```bash
cd /var/www/alodium/astro
npm install --legacy-peer-deps
mkdir -p /var/www/alodium/astro-public
```

`astro/.env.production` is gitignored, so create it on the server:

```ini
API_URL=http://SERVER/api/public
SITE_URL=http://SERVER:8080
TRACKER_URL=http://SERVER/tracker.js
```

`API_URL` has no trailing slash. Also update the `Sitemap:` line in `astro/public/robots.txt` to the real site URL.

### 5. Nginx

Two server blocks: the Laravel admin panel and API on port 80, and the Astro build output on port 8080.

`/etc/nginx/sites-available/alodium`:

```nginx
# Laravel: admin panel, public content API, tracker
server {
    listen 80;
    server_name SERVER;
    root /var/www/alodium/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    # Nginx's default is 1M. Media uploads (10 MB) and WordPress XML imports
    # (20 MB) need more. Keep this at least as high as PHP's post_max_size.
    client_max_body_size 30M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    # Only Laravel's front controller is executed as PHP. Any other .php file
    # (for example one that ended up in public/storage) gets a 404 instead.
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

# Astro: static public site
server {
    listen 8080;
    server_name SERVER;
    root /var/www/alodium/astro-public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.html;

    location / {
        try_files $uri $uri/ $uri.html =404;
    }

    # Serve Astro's 404 page with a real 404 status. "try_files ... /404.html"
    # would return it as 200, so missing URLs look like real pages to search engines.
    error_page 404 /404.html;
}
```

```bash
ln -s /etc/nginx/sites-available/alodium /etc/nginx/sites-enabled/alodium
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

If a firewall is active (`ufw`, or the hosting provider's firewall), open ports 22, 80 and 8080.

### 6. Queue worker (Supervisor)

The queue runs Astro rebuilds and other background work.

`/etc/supervisor/conf.d/alodium-worker.conf`:

```ini
[program:alodium-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/alodium/artisan queue:work --sleep=3 --tries=3 --timeout=660
user=www-data
numprocs=1
autostart=true
autorestart=true
; Longer than --timeout, so a running Astro build finishes when the worker restarts
stopwaitsecs=670
redirect_stderr=true
stdout_logfile=/var/www/alodium/storage/logs/worker.log
```

`--timeout=660` is required: Astro builds take minutes, and the default (60 s) kills them.

Create the log file before starting. Supervisor will not start the program if it cannot open the log:

```bash
touch /var/www/alodium/storage/logs/worker.log
chown www-data:www-data /var/www/alodium/storage/logs/worker.log

supervisorctl reread
supervisorctl update
supervisorctl status   # alodium-worker_00 should be RUNNING
```

### 7. Scheduler (cron)

```bash
crontab -u www-data -e
```

```
* * * * * cd /var/www/alodium && php artisan schedule:run >> /dev/null 2>&1
```

No scheduled tasks are defined yet. The cron entry means future ones (such as scheduled publishing) run without further server changes.

### 8. Automatic Astro rebuilds

The Astro site is static. It is rebuilt automatically whenever a change becomes visible publicly: content is published, a published post or page is edited, unpublished or deleted, or WordPress content is imported. Rebuilds run on the queue, so saving in the editor never waits for a build. Several changes in a row result in one build, and two builds never run at the same time.

```bash
cd /var/www/alodium
cp deploy/rebuild-astro.sh /var/www/alodium/rebuild-astro.sh
chmod 755 /var/www/alodium/rebuild-astro.sh

chown -R www-data:www-data /var/www/alodium/astro /var/www/alodium/astro-public

# www-data's home directory is /var/www. Astro writes its telemetry settings
# to ~/.config, and npm may write logs to ~/.npm.
mkdir -p /var/www/.config /var/www/.npm
chown -R www-data:www-data /var/www/.config /var/www/.npm
```

Generate a webhook secret:

```bash
openssl rand -hex 32
```

Add to `.env`:

```ini
ASTRO_REBUILD_SCRIPT=/var/www/alodium/rebuild-astro.sh
WEBHOOK_SECRET=the-generated-secret
# Must be higher than the worker's --timeout (660), or a second worker
# starts the same build while the first is still running.
DB_QUEUE_RETRY_AFTER=720
```

```bash
php artisan config:cache
supervisorctl restart alodium-worker:*
```

The script runs as `www-data` (the queue worker's user) and needs no `sudo`.

Run the first build by calling the webhook:

```bash
curl -X POST http://SERVER/api/webhook/rebuild -H "X-Webhook-Secret: the-generated-secret"
```

The response is `202 Accepted`, and the build runs in the background. Check progress in:

- `storage/logs/astro-rebuild.log`: start, finish or failure of each build
- `storage/logs/laravel.log`: build output and errors
- `storage/logs/worker.log`: the queue worker

A failed build is retried once. The live site keeps the previous version until a build succeeds. Then open `http://SERVER:8080`.

### Updating the application

**Code changes**

```bash
# on the server
cd /var/www/alodium
git pull
composer install --no-dev --optimize-autoloader   # if composer.json/composer.lock changed
php artisan migrate --force                       # if migrations were added
cd astro && npm install --legacy-peer-deps && cd ..
chown -R www-data:www-data /var/www/alodium       # files pulled as root are owned by root
php artisan config:cache
supervisorctl restart alodium-worker:*
```

Admin panel assets are always built locally:

```bash
# locally, in the project root (npm install --legacy-peer-deps first if package.json changed)
npm run build
scp -r public/build/* root@SERVER:/var/www/alodium/public/build/
```

If the Astro project itself changed (templates, styles), trigger a rebuild with the webhook `curl` command above.

**Content changes**

Publishing, editing, unpublishing or deleting posts and pages in the admin panel triggers an Astro rebuild automatically. No manual steps are needed. Changes appear on the public site once the build finishes, usually within a few minutes.

### Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Astro build fails with `Cannot find module` | `astro/node_modules` is gitignored. Run `npm install --legacy-peer-deps` in `astro/` after every deploy. |
| Astro build fails with an out-of-memory error | No swap on a 1 GB server. See [Swap file](#2-swap-file). |
| Astro build fails with `EACCES` under `/var/www/.config` | www-data's home is not writable. See step 8. |
| Public site shows old or placeholder content | `astro/.env.production` is missing or points to the wrong `API_URL`. It is gitignored and must be created on the server. |
| Rebuilds never happen | The worker is not running (`supervisorctl status`), `ASTRO_REBUILD_SCRIPT` is not set, or `config:cache` was not run after editing `.env`. |
| Build is killed after 60 seconds | The worker is missing `--timeout=660`. |
| `.env` changes have no effect | Configuration is cached. Run `php artisan config:cache`. |
| Uploads fail with `413 Request Entity Too Large` | `client_max_body_size` is missing in Nginx. |
| Uploads fail without an error | Upload limits in `/etc/php/8.4/fpm/php.ini`. Restart `php8.4-fpm` after editing. |
| `git pull` fails with "dubious ownership" | The repo is owned by www-data. Run `git config --global --add safe.directory /var/www/alodium` once. |
| Building assets on the server runs out of memory | Build them locally and copy them with `scp -r public/build/*`. |

## License

© 2026 John Ahlenhed. All rights reserved. Commercial use requires explicit permission from the author.