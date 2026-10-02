# Monks CRM

Internal lead-management CRM for Hybrid Monks LLP (crm.hybridmonks.com). The product name is set by `APP_NAME`.
Project rules for contributors and AI agents: [CLAUDE.md](CLAUDE.md). Build status: [docs/PROGRESS.md](docs/PROGRESS.md).

Production runs as a Docker container on Render with a Neon PostgreSQL database (see CLAUDE.md, "Hosting").

## Requirements
- PHP 8.3+ with `pdo_pgsql`, `pdo_sqlite`, `mbstring`, `openssl`, `bcmath`, `intl`
- Composer 2, Node 20+, PostgreSQL 16+ (17 recommended; production is Neon)
- Docker (optional, for the production image and for running Postgres locally)

## Local setup
```bash
git clone <repo-url> crm && cd crm
composer install
cp .env.example .env            # set DB_* for your local PostgreSQL (and MAIL_* for real emails)
php artisan key:generate
php artisan migrate --seed      # schema + statuses, sources, tags, settings (no users)
php artisan crm:create-admin    # interactive: creates your first admin
npm install
npm run build                   # or `npm run dev` while working on the UI
php artisan serve               # http://localhost:8000
```

No local PostgreSQL? Run one in Docker (data persists in a named volume):
```bash
docker run -d --name monks-crm-postgres -e POSTGRES_PASSWORD=<choose-one> -e POSTGRES_DB=monks_crm \
  -p 127.0.0.1:5432:5432 -v monks-crm-pgdata:/var/lib/postgresql/data postgres:17-alpine
```

Optional demo data (local only, refuses in production):
```bash
php artisan db:seed --class=DemoSeeder   # 1 manager, 2 sales users, 50 leads; prints a one-time password
```

## Tests
```bash
php artisan test            # fast default run: SQLite in memory
```

Against PostgreSQL (uses a dedicated test database; every run drops and recreates its tables):
```bash
cp .env.testing.pgsql.example .env.testing.pgsql   # fill in host/port/user/password
composer test:pgsql
```

If your local PHP has no `pdo_pgsql`, run the same suite inside the image's `base` stage. The project
is streamed into the container because Windows/macOS bind mounts make Laravel very slow:
```bash
docker build --target base -t monks-crm-php:base .
tar -cf - --exclude=./node_modules --exclude=./.git . | docker run -i --rm \
  --network <network-with-your-postgres> -e DB_HOST=<postgres-host> -e DB_PORT=5432 \
  monks-crm-php:base sh -c 'mkdir -p /work && tar -xf - -C /work && cd /work && composer test:pgsql'
```

## Production image
```bash
docker build -t monks-crm .
docker run --rm -p 8080:8080 -e APP_KEY=base64:... -e APP_URL=http://localhost:8080 \
  -e DB_URL="postgresql://USER:PASSWORD@HOST/DB?sslmode=require" monks-crm
```
The container listens on `$PORT` (Render sets it), runs as a non-root user, logs to stderr, and builds
Laravel's config/route/view caches at start. Migrations are not run automatically:
`php artisan migrate --force` is a deploy step (step 7). For one-off commands in the container shell,
`php artisan crm:create-admin` works as is; `tinker` needs `XDG_CONFIG_HOME=/tmp php artisan tinker`
(the non-root user cannot write the default config dir).
