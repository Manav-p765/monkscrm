# Monks CRM runbook

Short, practical steps for running production. Settings and first deploy: [docs/DEPLOY.md](docs/DEPLOY.md).
Production: Render web service (Docker, free tier: no shell) + Neon project `monkcrm` (Singapore, branch
`production`, database `neondb`). Secrets live in Render and Bitwarden only: never in chat, tickets or git.

## Run migrations

Render has no shell or pre-deploy command, so schema changes are applied from your machine.

**When:** before (or right after) deploying a commit that adds files under `database/migrations/`. Additive
migrations (new tables/columns) are safe either side of the deploy; for anything that renames or drops,
deploy code that works with both schemas first.

**You need:** this repo at the commit being deployed, PHP with `pdo_pgsql` (README), the Neon **direct**
connection string and the production `APP_KEY` (both in Bitwarden). For a file backup: PostgreSQL 18 client
tools (`pg_dump`) and `age` (`winget install FiloSottile.age`) plus the backup age **public** key.

```powershell
powershell -ExecutionPolicy Bypass -File scripts\migrate-production.ps1
```

The script:
1. asks for the direct URL and `APP_KEY` with hidden prompts (never saved to disk or shell history) and sets
   `APP_ENV=production`, `APP_DEBUG=false` for its own process only;
2. shows `migrate:status` and waits for you to type `yes`;
3. backs up first: `pg_dump` piped straight into `age` (encrypted file in `%USERPROFILE%\monks-crm-backups`),
   or, without those tools, walks you through creating a Neon branch `backup-before-migrate-<date>` and
   waits for `branch created`;
4. runs `migrate --force`, then the base seeder (statuses, sources, tags, settings; safe to re-run);
5. on the first deploy (no users yet) offers `crm:create-admin`;
6. clears every variable it set, even on failure.

**If it fails:** read the error. Migrations run one by one, so `migrate:status` shows how far it got. Fix
forward with a new migration if you can. To go back, restore the backup: Neon Console → Branches →
`production` → **Restore** from the `backup-before-migrate-*` branch (or point in time just before you ran the
script), or restore the encrypted file ("Restore a backup").

Delete old `backup-before-migrate-*` branches once a deploy is confirmed good (the free plan allows 10 branches).
