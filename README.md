# Monks CRM

Internal lead-management CRM for Hybrid Monks LLP (crm.hybridmonks.com). The product name is set by `APP_NAME`.
Project rules for contributors and AI agents: [CLAUDE.md](CLAUDE.md). Build status: [docs/PROGRESS.md](docs/PROGRESS.md).

## Requirements
- PHP 8.3+ with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `bcmath`
- Composer 2, Node 20+, MySQL 8

## Local setup
```bash
git clone <repo-url> crm && cd crm
composer install
cp .env.example .env            # then set DB_* (and MAIL_* if you want real emails)
php artisan key:generate
php artisan migrate --seed      # schema + statuses, sources, tags, settings (no users)
php artisan crm:create-admin    # interactive: creates your first admin
npm install
npm run build                   # or `npm run dev` while working on the UI
php artisan serve               # http://localhost:8000
```

Optional demo data (local only, refuses in production):
```bash
php artisan db:seed --class=DemoSeeder   # 1 manager, 2 sales users, 50 leads; prints a one-time password
```

## Tests
```bash
php artisan test
```
