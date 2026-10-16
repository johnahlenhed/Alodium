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

Alodium is designed to be deployed on a Hetzner VPS managed by Laravel Forge. See the deployment documentation for full instructions.

## License

© 2026 John Ahlenhed. All rights reserved. Commercial use requires explicit permission from the author.