# Alodium

A self-hosted content platform for freelance web developers. Combines a modern CMS, privacy-first analytics, a WordPress content importer, and an Astro-powered public site giving clients full ownership of their data, their content, and their corner of the web. No Google. No plugins. No platform lock-in.

## Stack

- **Admin panel:** Laravel + Inertia.js + React
- **Public site:** Astro
- **Database:** MySQL
- **Email:** Resend
- **Media:** Spatie Media Library

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL

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

## Deployment

Alodium is designed to be deployed on a Hetzner VPS managed by Laravel Forge. See the deployment documentation for full instructions.

## License

© 2026 John Ahlenhed. All rights reserved. Commercial use requires explicit permission from the author.