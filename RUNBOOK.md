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

## Backups

Three layers:
1. **Neon point-in-time restore**: the free plan keeps **6 hours** of history. For "we just broke something".
2. **Nightly encrypted file** (`.github/workflows/backup.yml`): 02:00 IST, `pg_dump` (Postgres 18) piped into
   `age`, uploaded to Cloudflare R2. Keeps 30 daily (`daily/`) and 12 monthly (`monthly/`, from the 1st).
3. **Monthly restore test** (`.github/workflows/restore-test.yml`, 2nd of the month): restores the latest
   backup into a throwaway database, checks the core tables, prints row counts (never data), and fails if
   the newest backup is more than 2 days old.

A failed run fails the workflow and GitHub emails the person who last changed that workflow's schedule
(make sure that is an admin who reads email; GitHub → Settings → Notifications → Actions: "Send
notifications for failed workflows only").

### One-time setup

**1. age key pair** (encrypts backups; the private key is the only way to read them).
```powershell
winget install FiloSottile.age
age-keygen -o backup-age.key        # prints "Public key: age1..."
```
- Bitwarden: new **Secure note** "Monks CRM – backup age key", paste the whole file content (the
  `AGE-SECRET-KEY-1...` line and the public key). **Two people** must hold it: share it into a Bitwarden
  collection both can open, and have the second person confirm they can see it.
- Then delete the file: `Remove-Item backup-age.key`. Losing every copy of this key = losing every backup.

**2. Cloudflare R2 bucket and token**
1. Cloudflare dashboard → **R2 Object Storage** → **Create bucket**: name `monks-crm-backups`, location hint
   **Asia-Pacific (APAC)**, default storage class Standard. Leave public access **off** (the default).
2. R2 → **Manage API tokens** → **Create API token** (Account API token): permission **Object Read & Write**,
   **Apply to specific buckets only** → `monks-crm-backups`, TTL forever. Create.
3. Copy the **Access Key ID**, **Secret Access Key** and your **Account ID** (R2 overview page, right side).
   They are shown once. Store them in Bitwarden ("Monks CRM – R2 backup token").

**3. GitHub secrets** (repo → Settings → Secrets and variables → Actions → New repository secret):

| Secret | Value |
|---|---|
| `NEON_DIRECT_URL` | Neon **direct** connection string (no `-pooler`), ending `?sslmode=require` |
| `BACKUP_AGE_PUBLIC_KEY` | The `age1...` public key |
| `BACKUP_AGE_PRIVATE_KEY` | The `AGE-SECRET-KEY-1...` line (used only by the restore test) |
| `R2_ACCOUNT_ID` | Cloudflare account ID |
| `R2_ACCESS_KEY_ID` | R2 token access key ID |
| `R2_SECRET_ACCESS_KEY` | R2 token secret |
| `R2_BUCKET` | `monks-crm-backups` |

**4. Test:** Actions → **Backup** → **Run workflow**. When it is green, Actions → **Restore test** → **Run
workflow**. Both must pass before go-live.

GitHub turns off scheduled workflows in a repo with no commits for 60 days; the monthly checks below catch it.

## Restore a backup

**Choose the gentlest option that works:**

**A. Mistake in the last 6 hours: Neon point-in-time restore.** Neon Console → `monkcrm` → Branches →
`production` → **Restore** → pick a time just before the mistake → Neon keeps the current state as a backup
branch automatically → Restore. The connection strings don't change; the app reconnects by itself.

**B. Older: restore the nightly file.** About 15 minutes. You need Git Bash, `age`, PostgreSQL 18 client tools
(`C:\Program Files\PostgreSQL\18\bin`), the age private key (Bitwarden) and the Neon direct URL (Bitwarden).

1. **Stop writes:** Render → service → **Suspend** (users see an error page until you resume).
2. **Safety copy of the current state:** Neon → Branches → **New branch** from `production`, name
   `before-restore-<date>`, current point in time.
3. **Download the file:** Cloudflare → R2 → `monks-crm-backups` → `daily/` (or `monthly/`) → pick the date →
   **Download**.
4. **Restore** (Git Bash, from the download folder; Git Bash pipes are binary-safe, PowerShell 5.1's are not):
   ```bash
   export PATH="/c/Program Files/PostgreSQL/18/bin:$PATH"
   read -rs DB_URL        # paste the Neon DIRECT url (hidden), press Enter
   read -rs AGE_KEY       # paste the AGE-SECRET-KEY-1... line (hidden), press Enter
   (umask 077; printf '%s\n' "$AGE_KEY" > ~/restore-age.key); unset AGE_KEY
   age --decrypt --identity ~/restore-age.key neondb-2026-10-09.dump.age \
     | pg_restore --clean --if-exists --no-owner --no-privileges --single-transaction --dbname="$DB_URL"
   rm -f ~/restore-age.key
   psql "$DB_URL" -tA -c "select count(*) from users" -c "select count(*) from leads"
   unset DB_URL
   ```
   `--single-transaction`: if anything fails, nothing changes. Then delete the downloaded file.
5. **Resume:** Render → **Resume service**. Sign in and check recent leads.
6. Tell the team which backup was restored and what was lost (changes after that backup's time). Delete the
   `before-restore-*` branch after a week if all is well.
