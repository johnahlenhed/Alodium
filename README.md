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
- Node.js 22.22+
- MySQL

### PHP extensions

- `php8.4-gd`: image processing and WebP conversion
- `php8.4-xml`: WordPress XML import
- `php8.4-mysql`
- `php8.4-mbstring`
- `php8.4-curl`
- `php8.4-zip`
- `php8.4-bcmath`
- `php8.4-intl`

On Debian/Ubuntu:

```bash
sudo apt install php-gd php-xml php-mysql php-mbstring php-curl php-zip php-bcmath php-intl
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
git clone https://github.com/johnahlenhed/Alodium.git
cd Alodium
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

Visitor countries come from the MaxMind GeoLite2-Country database, read locally; it is not in the repository. Locally it is optional: without it, visits are recorded without a country and the dashboard's Site status says so. To install it, follow [deployment step 6](#6-geoip-database-visitor-countries) from the project root (without `sudo -u www-data`).

## Deployment

A step-by-step guide to deploying Alodium on a single Ubuntu server, over HTTPS on two domains. One server runs both parts: the Laravel admin panel and API on `ADMIN_DOMAIN` (for example `admin.example.com`), and the static Astro public site on `SITE_DOMAIN` (for example `example.com`).

Throughout this guide, replace `ADMIN_DOMAIN` and `SITE_DOMAIN` with your domains, and `SERVER` in `ssh`, `scp` and `rsync` commands with your server's IP address. All commands are run as root (or with `sudo`) unless stated otherwise.

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
mysql
```

On Ubuntu, MySQL's root account uses socket authentication, so run `mysql` as root (or with `sudo`) without `-p`.

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
APP_URL=https://ADMIN_DOMAIN
# The public site: the dashboard's "View site" link, and visits from its own pages
# count as navigation within the site, not as referrals (analytics).
PUBLIC_SITE_URL=https://SITE_DOMAIN

DB_CONNECTION=mysql
DB_DATABASE=alodium
DB_USERNAME=alodium
DB_PASSWORD=a-strong-password

MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=hello@SITE_DOMAIN

QUEUE_CONNECTION=database
```

Then:

```bash
php artisan migrate --force
php artisan storage:link
```

#### Create the admin account

> **Registration is disabled and the seeder is blocked in production.**
> `/register` redirects to the login page, the seeder exits with a
> warning, and every account has full admin access. Create the first
> admin user with:
> ```bash
> php artisan tinker
> App\Models\User::create(['name' => 'Your Name', 'email' => 'you@example.com', 'password' => bcrypt('a-strong-password')]);
> ```
> To allow sign-ups temporarily, set `AUTH_REGISTRATION=true` in `.env`
> and run `php artisan config:cache`.

#### Email (Resend)

Add and verify the sending domain in Resend **before any mail is sent**, otherwise sending fails. In the Resend dashboard, add the domain under *Domains* and add the DNS records it shows (DKIM, plus SPF and the return-path records) at your DNS provider. Wait until Resend shows the domain as verified.

Create an API key with **Sending access** only, and put it in `RESEND_API_KEY`. Set `MAIL_FROM_ADDRESS` to an address on the verified domain, then:

```bash
php artisan config:cache
```

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

Visitor countries in the analytics need the MaxMind database: that is [step 6](#6-geoip-database-visitor-countries), after HTTPS.

### 4. Astro public site

`deploy/add-domain.sh` automates this section, the next one and step 6 (GeoIP) on a new server (`--help` explains it). Its `setup` step writes `.env.production` and creates `astro-public`, but doesn't run `npm install`.

```bash
cd /var/www/alodium/astro
npm install --legacy-peer-deps
mkdir -p /var/www/alodium/astro-public
```

`astro/.env.production` is gitignored, so create it on the server:

```ini
API_URL=https://ADMIN_DOMAIN/api/public
SITE_URL=https://SITE_DOMAIN
TRACKER_URL=https://ADMIN_DOMAIN/tracker.js
```

`API_URL` has no trailing slash. The site name, logo, colours, navigation and front page are not set here: they come from the admin panel's Settings page (`GET /api/public/site`). `robots.txt` needs nothing either: the build writes it (`astro/src/pages/robots.txt.ts`), with the sitemap URL taken from `SITE_URL`.

### 5. DNS, Nginx and HTTPS

`deploy/add-domain.sh setup` does the Nginx steps below, and `deploy/add-domain.sh certbot` checks DNS and then gets the certificate. You can run the `certbot` step again once DNS has propagated. You still create the DNS records yourself.

#### DNS

Create three A records pointing to the server's IP address:

| Type | Name | Value |
|---|---|---|
| A | `SITE_DOMAIN` | server IP |
| A | `www.SITE_DOMAIN` | server IP |
| A | `ADMIN_DOMAIN` | server IP |

If you use Cloudflare, set all three to **DNS only** (grey cloud, not proxied), so Let's Encrypt can verify them. Wait until the names resolve to the server before continuing:

```bash
ping SITE_DOMAIN
ping ADMIN_DOMAIN
```

#### Nginx

`ADMIN_DOMAIN` serves Laravel (the admin panel, public content API and tracker). `SITE_DOMAIN` and `www.SITE_DOMAIN` serve the Astro build output. Start with plain HTTP (`listen 80`) blocks that use the real domain names as `server_name`. Certbot (below) cannot install the certificate if `server_name` is an IP address or doesn't match the domain.

`/etc/nginx/sites-available/alodium`:

```nginx
# Laravel: admin panel, public content API, tracker
server {
    listen 80;
    server_name ADMIN_DOMAIN;
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
    listen 80;
    server_name SITE_DOMAIN www.SITE_DOMAIN;
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

If a firewall is active (`ufw`, or the hosting provider's firewall), open ports 22, 80 and 443. Port 8080 should stay closed. See [Server hardening](#12-server-hardening).

#### HTTPS (Let's Encrypt)

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d SITE_DOMAIN -d www.SITE_DOMAIN -d ADMIN_DOMAIN
```

Certbot gets one certificate for all three names, adds the HTTPS settings to the blocks above, and redirects HTTP to HTTPS. For reference, the result looks like this. The Laravel and Astro rules inside the blocks are unchanged and shortened here to `...`:

```nginx
server {
    server_name ADMIN_DOMAIN;
    root /var/www/alodium/public;
    # ... all the Laravel rules from above ...

    listen 443 ssl; # managed by Certbot
    ssl_certificate /etc/letsencrypt/live/SITE_DOMAIN/fullchain.pem; # managed by Certbot
    ssl_certificate_key /etc/letsencrypt/live/SITE_DOMAIN/privkey.pem; # managed by Certbot
    include /etc/letsencrypt/options-ssl-nginx.conf; # managed by Certbot
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem; # managed by Certbot
}

server {
    server_name SITE_DOMAIN www.SITE_DOMAIN;
    root /var/www/alodium/astro-public;
    # ... all the Astro rules from above ...

    listen 443 ssl; # managed by Certbot
    ssl_certificate /etc/letsencrypt/live/SITE_DOMAIN/fullchain.pem; # managed by Certbot
    ssl_certificate_key /etc/letsencrypt/live/SITE_DOMAIN/privkey.pem; # managed by Certbot
    include /etc/letsencrypt/options-ssl-nginx.conf; # managed by Certbot
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem; # managed by Certbot
}

# HTTP to HTTPS for all three names
server {
    listen 80;
    server_name SITE_DOMAIN www.SITE_DOMAIN ADMIN_DOMAIN;
    return 301 https://$host$request_uri;
}
```

Certbot writes the redirect as `if ($host = ...)` lines in one port 80 block per site. That does the same thing as the single block above.

Test both sites in a browser: `https://SITE_DOMAIN` and `https://ADMIN_DOMAIN`. Optionally, check the HTTPS setup at [ssllabs.com/ssltest](https://www.ssllabs.com/ssltest/). Certbot renews the certificate automatically.

### 6. GeoIP database (visitor countries)

The analytics resolve each visitor's country from their IP with the **MaxMind GeoLite2-Country** database, read locally on the server. No IP is sent to a third party, and IPs are never stored, only the country code. The file is not in the repository (it is large, and MaxMind's licence doesn't allow redistributing it), so download it here. Without it, every visit is recorded without a country: the Countries panel stays empty, and visits recorded before the file is installed never get one. The dashboard's **Site status** warns while the file is missing.

**One MaxMind account per client.** Every deployment uses a free MaxMind account and license key of its own, belonging to that client, never a key shared between clients: each install is then the client's own use of the GeoLite2 data, under MaxMind's terms.

`deploy/add-domain.sh geoip --maxmind-key -` does steps 2 and 3 below: it asks for the key without showing it, writes it into `.env`, refreshes the config cache and downloads the database. Without a key it only explains where to get one. `deploy/add-domain.sh all` runs it too.

1. Sign up for a free GeoLite account at [maxmind.com](https://www.maxmind.com/en/geolite2/signup), with the client's details. Log in, and in the account's GeoIP / GeoLite menu generate a license key.
2. Add it to `.env`, then refresh the config cache:

   ```ini
   MAXMIND_LICENSE_KEY=your-key
   ```

   ```bash
   cd /var/www/alodium
   php artisan config:cache
   ```

3. Download the database as `www-data`, so the monthly update (below) can replace the file later:

   ```bash
   sudo -u www-data mkdir -p storage/app/geoip
   sudo -u www-data php artisan location:update
   ls -l storage/app/geoip/GeoLite2-Country.mmdb   # about 9 MB, owned by www-data
   ```

The scheduler (step 8) runs `location:update` on the 1st of every month at 03:00, as long as `MAXMIND_LICENSE_KEY` is set; MaxMind's licence expects the data to be kept up to date. The dashboard warns if the file hasn't been updated for more than 45 days.

This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com).

### 7. Queue worker (Supervisor)

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

### 8. Scheduler (cron)

```bash
crontab -u www-data -e
```

```
* * * * * cd /var/www/alodium && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs `content:publish-scheduled` every minute. It publishes posts and pages whose scheduled time has passed, which then triggers an Astro rebuild. Without this cron entry, scheduled content is never published. It also runs the database backups (step 10) and, monthly, the GeoIP database update (step 6).

### 9. Automatic Astro rebuilds

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
curl -X POST https://ADMIN_DOMAIN/api/webhook/rebuild -H "X-Webhook-Secret: the-generated-secret"
```

The response is `202 Accepted`, and the build runs in the background. Check progress in:

- `storage/logs/astro-rebuild.log`: start, finish or failure of each build
- `storage/logs/laravel.log`: build output and errors
- `storage/logs/worker.log`: the queue worker

A failed build is retried once. The live site keeps the previous version until a build succeeds. Then open `https://SITE_DOMAIN`.

### 10. Database backups

`php artisan db:backup` runs every day at 02:00 UTC through the scheduler (step 8). It writes a compressed dump to `storage/app/backups/alodium-backup-YYYY-MM-DD-HHMM.sql.gz` and keeps the newest 30. A backup counts as successful only if `mysqldump` exits cleanly and the dump is complete. Failures are logged to `storage/logs/laravel.log` at error level.

Backup files are readable only by `www-data` (they contain user accounts and password hashes). Make a manual backup as that user, so the file gets the same owner:

```bash
cd /var/www/alodium
sudo -u www-data php artisan db:backup
```

List available backups:

```bash
ls -lh storage/app/backups/
```

To restore a backup manually:

```bash
php artisan down        # stop visitors and editors from writing during the restore
gunzip -c storage/app/backups/alodium-backup-YYYY-MM-DD-0200.sql.gz | \
  mysql -u <DB_USERNAME> -p alodium
php artisan up
```

Replace `<DB_USERNAME>` with the `DB_USERNAME` from your `.env`. `mysql` asks for its `DB_PASSWORD`.

The restore replaces the current tables with the ones in the backup. Changes made after the backup was taken are lost. After restoring, trigger an Astro rebuild with the webhook (step 9) so the public site matches the restored content.

#### Off-server copies (recommended)

Local backups don't survive the loss of the server. When object storage is configured, `db:backup` also encrypts each new backup and uploads it to an S3-compatible bucket. It keeps the newest 30 there (`DB_BACKUP_REMOTE_KEEP`). Only encrypted files ever leave the server. Without the settings below, backups stay local only, exactly as before.

**1. Create a bucket and an API key.** Any S3-compatible provider works: Cloudflare R2, Hetzner Object Storage, DigitalOcean Spaces or Backblaze B2. Create a private bucket, for example `alodium-backups`. Then create an API key that can read, write and delete objects **in that bucket only**. Pruning needs delete, and `db:backup-test-remote` needs read. Never use an account-wide admin key. For Cloudflare R2: *R2 → Manage API tokens → Object Read & Write*, limited to the bucket. The endpoint is `https://<account-id>.r2.cloudflarestorage.com` and the region is `auto`. Buckets created with the EU jurisdiction use `https://<account-id>.eu.r2.cloudflarestorage.com` instead. Copy the exact endpoint from the bucket settings. Other providers show their endpoint and region in the bucket settings.

**2. Generate an encryption passphrase:**

```bash
openssl rand -base64 32
```

**Store the passphrase outside the server too**, for example in a password manager. Copy it from the server's `.env` into the password manager as soon as it is generated, and test a restore using the password manager copy. It's needed to decrypt any remote backup. If the server is lost and the passphrase only existed in its `.env`, the remote backups can't be restored. The encryption protects backups if the bucket or the provider is compromised. It does not protect against someone with access to the server itself, since the passphrase is in `.env`.

**3. Add to `.env`:**

```ini
BACKUP_S3_KEY=...
BACKUP_S3_SECRET=...
BACKUP_S3_REGION=auto
BACKUP_S3_BUCKET=alodium-backups
# EU jurisdiction buckets: https://<account-id>.eu.r2.cloudflarestorage.com
BACKUP_S3_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
BACKUP_S3_USE_PATH_STYLE=true
BACKUP_ENCRYPTION_PASSPHRASE="the-generated-passphrase"
```

The quotes only protect characters like `=`, `+` and `/` in the `.env` file and are not part of the passphrase. When decrypting, type the passphrase without quotes.

**4. Apply and test:**

```bash
php artisan config:cache
sudo -u www-data php artisan db:backup-test-remote
```

The test encrypts a small file, uploads it, downloads and decrypts it, then deletes it. It reports the exact error if any step fails. After that, `db:backup` uploads automatically every night. Use `php artisan db:backup --no-upload` for a local-only backup.

A failed upload leaves the local backup and the remote backups untouched. It logs an error and makes `db:backup` exit with a failure code. If the bucket is configured but the passphrase is missing, nothing is uploaded, because backups are never uploaded unencrypted.

**Restoring from a remote backup.** On any machine with `openssl`, `gunzip` and `mysql`, and the AWS CLI for the download (for example `sudo snap install aws-cli --classic`):

```bash
# 1. List and download (or download the file from your provider's web console)
export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=...
# The BACKUP_S3_ENDPOINT from your .env (EU jurisdiction: https://<account-id>.eu.r2.cloudflarestorage.com)
ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
aws s3 ls s3://alodium-backups/backups/ --endpoint-url "$ENDPOINT" --region auto
aws s3 cp s3://alodium-backups/backups/alodium-backup-YYYY-MM-DD-0200.sql.gz.enc . \
  --endpoint-url "$ENDPOINT" --region auto

# 2. Decrypt (asks for the passphrase)
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 \
  -in alodium-backup-YYYY-MM-DD-0200.sql.gz.enc -out alodium-backup-YYYY-MM-DD-0200.sql.gz

# 3. Restore (on the server, from /var/www/alodium)
php artisan down
gunzip -c alodium-backup-YYYY-MM-DD-0200.sql.gz | mysql -u <DB_USERNAME> -p alodium
php artisan up
```

As above, `<DB_USERNAME>` is the `DB_USERNAME` from your `.env`.

The encryption is OpenSSL AES-256-CBC with a PBKDF2-derived key (600,000 iterations). It keeps the contents secret but does not detect deliberate tampering. Accidental corruption is caught when `gunzip` fails its checksum.

**Test a full restore at least once**, into a scratch database, so you know the backups and the passphrase actually work before you need them. On Ubuntu, MySQL's root account uses socket authentication, so use `sudo mysql`:

```bash
gunzip -t alodium-backup-YYYY-MM-DD-0200.sql.gz   # checks the file is intact before loading it
sudo mysql -e "CREATE DATABASE alodium_restore_test"
gunzip -c alodium-backup-YYYY-MM-DD-0200.sql.gz | sudo mysql alodium_restore_test
sudo mysql -e "SELECT COUNT(*) FROM alodium_restore_test.posts; DROP DATABASE alodium_restore_test"
rm alodium-backup-YYYY-MM-DD-0200.sql.gz          # the decrypted copy
```

> **Never restore a test into the live database or your local development database.** Always use a separate scratch database like the one above. Delete decrypted backup files afterwards, because they contain user accounts and password hashes.

#### Disaster recovery

If the server is lost:

1. Create a new droplet.
2. Follow deployment steps 1–9 and 12, and point the DNS records at the new server's IP address (Certbot then issues a new certificate).
3. Restore the latest backup as described in "Restoring from a remote backup" above.
4. Re-add the `.env` values from the password manager: `APP_KEY`, the database password (`DB_PASSWORD`), S3 credentials, encryption passphrase, Resend, Sentry, the webhook secret and `MAXMIND_LICENSE_KEY`. Then run `php artisan config:cache`. Use the old `APP_KEY`, not the one `key:generate` created on the new server. Without it, every session is invalidated and anything Laravel encrypted becomes unreadable. Save these values outside the server, so they survive its loss.
5. Restore uploaded media (see below).
6. Trigger an Astro rebuild with the webhook (step 9).

#### What the backups don't cover

`db:backup` copies the database only. These need their own copy:

- **Uploaded media** in `storage/app/public`. When off-server copies are configured, `media:backup-remote` copies it to the bucket every night (see "Off-server media copies" below). Without a bucket, copy it off the server yourself, for example with `rsync -a root@SERVER:/var/www/alodium/storage/app/public/ ./alodium-media/`.
- **The `.env` file.** Keep its values in a password manager.
- **Server configuration** (Nginx, Supervisor, cron). The deployment steps rebuild it.

#### Off-server media copies

When the `BACKUP_S3_*` settings above are configured, `php artisan media:backup-remote` runs every day at 02:30 UTC. It copies everything in `storage/app/public` (uploads and their conversions) to the same bucket under `media/`, keeping the folder structure. Only new files and files whose size changed are uploaded. Remote files are never deleted, so media deleted in the admin panel can still be recovered from the bucket. Without the settings, the command does nothing.

Media files are stored as they are, not encrypted, because they are public on the site anyway. A failed upload is logged at error level and makes the command exit with a failure code. The other files are still copied.

Run it by hand, for example right after setting up the bucket:

```bash
cd /var/www/alodium
sudo -u www-data php artisan media:backup-remote
```

**Restoring media onto a new server**, with the AWS CLI and the same credentials and endpoint as for database backups:

```bash
export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=...
# The BACKUP_S3_ENDPOINT from your .env (EU jurisdiction: https://<account-id>.eu.r2.cloudflarestorage.com)
ENDPOINT=https://<account-id>.r2.cloudflarestorage.com

aws s3 sync s3://alodium-backups/media/ /var/www/alodium/storage/app/public/ \
  --endpoint-url "$ENDPOINT" --region auto

chown -R www-data:www-data /var/www/alodium/storage/app/public
cd /var/www/alodium
php artisan storage:link        # only if public/storage doesn't exist yet
```

Restore the database first: Spatie's `media` table says which files belong to which post or page. The sync also brings back files that were deleted on purpose, which is harmless, because nothing in the database points to them. If image conversions (thumbnails, WebP, the social sharing image) are missing, regenerate them:

```bash
sudo -u www-data php artisan media-library:regenerate
```

### 11. Sentry error monitoring (optional)

Sentry collects unhandled errors from production in a dashboard, with stack traces and request details. It covers both Laravel and the admin panel in the browser. It is only active when `APP_ENV=production`, so local and test errors are never sent.

1. Create a free account at [sentry.io](https://sentry.io) and create a Laravel project.
2. Copy the DSN from the project settings (Client Keys).
3. Add it to `.env` on the server:

   ```ini
   SENTRY_LARAVEL_DSN=https://...@....ingest.sentry.io/...
   SENTRY_TRACES_SAMPLE_RATE=0.1
   ```

4. Apply and verify:

   ```bash
   php artisan config:cache
   php artisan sentry:test   # sends a test event; it should appear in Sentry within a minute
   ```

No frontend rebuild is needed. Laravel passes the same DSN to the browser when the page loads, so the locally built assets work unchanged.

Notes:

- `SENTRY_TRACES_SAMPLE_RATE` is the share of requests recorded for performance monitoring (0.1 = 10%). Tracker requests from the public site count too, so lower it if the free quota runs out.
- Personal data (IP addresses, user e-mails) is not sent by default (`send_default_pii` is off in `config/sentry.php`). This keeps the privacy-first analytics promise.
- Profiling (`SENTRY_PROFILES_SAMPLE_RATE`, default 0.1) needs the PHP `excimer` extension. Without it, profiling is simply skipped.

### 12. Server hardening

**Firewall.** Allow SSH *before* enabling the firewall, or the current session is locked out:

```bash
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
ufw status   # 22, 80 and 443 allowed; 8080 closed (not listed, or an explicit DENY rule)
```

**SSH.** Make sure you can log in with an SSH key first. Then set these in `/etc/ssh/sshd_config`:

```
PermitRootLogin prohibit-password
PasswordAuthentication no
```

```bash
systemctl restart ssh    # the service is called "ssh" on Ubuntu, not "sshd"
sshd -T | grep -E 'permitrootlogin|passwordauthentication'   # shows the values in effect
```

If `sshd -T` shows `permitrootlogin without-password`, that is fine: `without-password` is the old name for `prohibit-password` and means the same.

Files in `/etc/ssh/sshd_config.d/` are read first and take precedence, and some cloud images set `PasswordAuthentication yes` there. If `sshd -T` still shows `yes`, change it in that file too. Open a new connection in a second terminal and check that it works before closing the first one.

**Automatic security updates:**

```bash
apt install -y unattended-upgrades
dpkg-reconfigure unattended-upgrades
```

By default, unattended-upgrades only covers Ubuntu's own repositories, so PHP installed from `ppa:ondrej/php` is **not** updated automatically. To allow it, read the PPA's origin (the `o=` and `a=` values):

```bash
apt-cache policy | grep -A2 'ppa.launchpadcontent.net/ondrej/php'
```

The `release` line in its output shows the origin and archive, for example `o=LP-PPA-ondrej-php,a=noble`. The origin is `LP-PPA-ondrej-php` and the archive is your release codename. Add this line to the `Unattended-Upgrade::Allowed-Origins` list in `/etc/apt/apt.conf.d/50unattended-upgrades`:

```
"LP-PPA-ondrej-php:${distro_codename}";
```

Then check that it is picked up:

```bash
unattended-upgrade --dry-run -d 2>&1 | grep 'Allowed origins'
```

**MySQL** should listen on 127.0.0.1 only. Check `bind-address` in `/etc/mysql/mysql.conf.d/mysqld.cnf`, and confirm:

```bash
ss -ltnp | grep 3306   # 127.0.0.1:3306, not 0.0.0.0:3306
```

**Cron.** The scheduler entry (step 8) must exist only for `www-data`, not also for root. Otherwise every scheduled job (publishing, backups) runs twice:

```bash
crontab -u www-data -l   # contains schedule:run
crontab -l               # as root: must not contain schedule:run
```

### 13. Users and roles

There are two roles:

- **Admin:** full access.
- **Editor:** can write, publish and edit their own posts and pages, and edit published content by others. Editors cannot delete content, use Analytics or Import, manage users, or edit other authors' drafts.

Admins create users on the **Users** page (name, email and role). The new user gets an invitation email with a link to set their password. The link is valid for 60 minutes. After that, "Forgot your password?" on the login page sends a new one. This needs working email (see [Email (Resend)](#email-resend) in step 3). Roles can be changed on the same page.

### 14. WordPress import

Admins import a WordPress export (**Tools > Export > All content**, an XML file of up to 20 MB) on the **Import** page. Published posts and pages stay published, drafts stay drafts, and items whose slug already exists are skipped, so an import can be run again.

**Download images from the old site** (checked by default) copies the images used in posts and pages, and the featured images, into the media library, and points the content at the copies. Only images on the exported site's own addresses are copied (`base_site_url`, `base_blog_url` and the attachments' hosts); images hosted elsewhere stay where they are. Unchecked, only the text is imported, and featured images are not copied either. Links to attachment pages and other files on the old site are not changed.

The images are downloaded after the upload, on the queue, so **the queue worker must be running** (`alodium-worker`, step 7). The import's page shows the progress and, when it is done, every skipped image with its reason. If nothing moves for a minute, check `supervisorctl status`. The limits are set in `config/import.php` and can be changed in `.env`:

| Limit | Default | Variable |
|---|---|---|
| Per image | 10 MB, 25 megapixels | `IMPORT_MAX_IMAGE_BYTES`, `IMPORT_MAX_MEGAPIXELS` |
| Per post or page | 50 images | `IMPORT_MAX_IMAGES_PER_ITEM` |
| Per import | 1500 images, 1 GiB | `IMPORT_MAX_IMAGES_PER_RUN`, `IMPORT_MAX_TOTAL_BYTES` |
| Free disk space | stops below 2 GiB | `IMPORT_MIN_FREE_DISK_BYTES` |

Run `php artisan config:cache` after changing them.

**After a deploy**, import `docs/testdata/ssrf-smoke.xml` (from the repository) with the box checked. It checks that the server refuses to download from internal addresses. A correct result: 1 post imported, 5 images found, 0 downloaded, 5 skipped, all "Address not allowed (private or internal)". Then delete the draft "SSRF smoke test (delete me)" under **Posts**. Any other result means the image downloads must not be used until it is fixed; see `docs/ARCHITECTURE.md`, section 9.

Plugin content is not imported. WordPress's own shortcodes (galleries, captions, audio, video, playlists, embeds) become images and links; other shortcodes stay as text. The result page lists, per post or page, everything that was converted, lost or repaired (for example invalid characters in the file). Known gaps are listed in `docs/ARCHITECTURE.md`, section 12.

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

If a release adds a new image conversion (for example the 1200×630 `og` image used for social sharing), generate it for media uploaded before that release:

```bash
sudo -u www-data php artisan media-library:regenerate --only=og
```

**After PHP updates**

PHP-FPM is restarted together with the package, but the queue worker is a long-running process and keeps using the old PHP binary until it is restarted:

```bash
supervisorctl restart alodium-worker:*
php -v          # CLI version, used by the worker and the scheduler
php-fpm8.4 -v   # FPM version, used by the website
```

Both should show the same, new version.

**After configuring a domain**

Update the domain settings as described in steps 4 and 5, then trigger an Astro rebuild with the webhook.

**Content changes**

Publishing, editing, unpublishing or deleting posts and pages in the admin panel triggers an Astro rebuild automatically. No manual steps are needed. Changes appear on the public site once the build finishes, usually within a few minutes.

### Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Astro build fails with `Cannot find module` | `astro/node_modules` is gitignored. Run `npm install --legacy-peer-deps` in `astro/` after every deploy. |
| Astro build fails with an out-of-memory error | No swap on a 1 GB server. See [Swap file](#2-swap-file). |
| Astro build fails with `EACCES` under `/var/www/.config` | www-data's home is not writable. See step 9. |
| Public site shows old or placeholder content | `astro/.env.production` is missing or points to the wrong `API_URL`. It is gitignored and must be created on the server. |
| Rebuilds never happen | The worker is not running (`supervisorctl status`), `ASTRO_REBUILD_SCRIPT` is not set, or `config:cache` was not run after editing `.env`. |
| Build is killed after 60 seconds | The worker is missing `--timeout=660`. |
| `.env` changes have no effect | Configuration is cached. Run `php artisan config:cache`. |
| Uploads fail with `413 Request Entity Too Large` | `client_max_body_size` is missing in Nginx. |
| Uploads fail without an error | Upload limits in `/etc/php/8.4/fpm/php.ini`. Restart `php8.4-fpm` after editing. |
| `git pull` fails with "dubious ownership" | The repo is owned by www-data. Run `git config --global --add safe.directory /var/www/alodium` once. |
| Building assets on the server runs out of memory | Build them locally and copy them with `scp -r public/build/*`. |
| Certbot: "Could not automatically find a matching server block" | `server_name` is an IP address or doesn't match the domain. Fix `server_name`, reload Nginx, then run `certbot install --cert-name SITE_DOMAIN`. |
| Invitation or password reset emails only appear in `laravel.log` | `MAIL_MAILER` is still `log`, or the Resend sending domain isn't verified. Run `php artisan config:cache` after fixing `.env`. |
| Astro build fails with `astro: not found` | Dependencies are missing. Run `npm install --legacy-peer-deps` in `astro/` and make sure the files are owned by `www-data`. |

## License

© 2026 John Ahlenhed. All rights reserved. Commercial use requires explicit permission from the author.