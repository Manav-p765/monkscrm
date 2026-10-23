# HybridMonks CRM runbook

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
- Bitwarden: new **Secure note** "HybridMonks – backup age key", paste the whole file content (the
  `AGE-SECRET-KEY-1...` line and the public key). **Two people** must hold it: share it into a Bitwarden
  collection both can open, and have the second person confirm they can see it.
- Then delete the file: `Remove-Item backup-age.key`. Losing every copy of this key = losing every backup.

**2. Cloudflare R2 bucket and token**
1. Cloudflare dashboard → **R2 Object Storage** → **Create bucket**: name `monks-crm-backups`, location hint
   **Asia-Pacific (APAC)**, default storage class Standard. Leave public access **off** (the default).
2. R2 → **Manage API tokens** → **Create API token** (Account API token): permission **Object Read & Write**,
   **Apply to specific buckets only** → `monks-crm-backups`, TTL forever. Create.
3. Copy the **Access Key ID**, **Secret Access Key** and your **Account ID** (R2 overview page, right side).
   They are shown once. Store them in Bitwarden ("HybridMonks – R2 backup token").

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

## Monitoring

**Uptime (UptimeRobot, free):**
1. uptimerobot.com → sign up with a shared admin mailbox → **New monitor**.
2. Type **Keyword**, URL `https://crm.hybridmonks.com/healthz`, keyword `ok` (alert when *not* present),
   interval **5 minutes**. Friendly name "HybridMonks".
3. Alert contacts: the admins' email addresses (add a second person). Save.
4. `/healthz` never touches the database, so monitoring does not keep Neon awake or use its compute hours.
   It does keep the Render free instance from spinning down (Render's 750 free hours/month cover one
   service running all month).

**Neon usage:** the Free plan has **no** usage or spending alerts (Neon only offers them on Launch/Scale), so
check by hand monthly (below). What happens at the Free limits: past **100 CU-hours** in a month the database
**is suspended** (the app goes down) until the month resets; past **1 GB** storage writes fail; past **5 GB**
egress the compute is suspended. To make compute hours last, set Neon → Branches → `production` → compute →
**Edit** → autoscaling max **0.5 CU** (enough for this app). If usage gets close to a limit, upgrade to
Launch (pay as you go) and turn on Billing → **Spending notifications**.

## Deploy

Render deploys every push to `main` automatically (build ≈ 5 min, then a zero-downtime swap once `/healthz`
answers).

1. Tests pass locally: `php artisan test` and `composer test:pgsql`.
2. **If the commit adds migrations:** run `scripts\migrate-production.ps1` **before** pushing (migrations must
   be additive so the old code keeps working; see "Run migrations").
3. Push to `main` (only after review). Watch Render → service → **Events** until "Deploy live".
4. Check: `https://crm.hybridmonks.com/healthz` says `ok`, sign in, open Leads.
5. If the deploy changed mail settings: "Check email".

A deploy whose boot checks fail (missing `APP_KEY`/`APP_URL`/`DB_URL`, debug on) never goes live: Render
keeps the previous version running and the deploy log names the setting.

## Roll back a bad deploy

1. Render → service → **Events** → find the last good deploy → **Rollback**. Takes about a minute.
2. Auto-deploy will ship the next push to `main` again, so fix forward with `git revert <bad commit>` and push.
3. Migrations are **not** rolled back. Additive migrations are harmless for older code. If a migration itself
   was the problem, see "Run migrations > If it fails".

## Check email

After deploys that touch mail, and when someone says an invite or reset never arrived:
```powershell
powershell -ExecutionPolicy Bypass -File scripts\production-artisan.ps1 crm:mail-test you@hybridmonks.com
```
It asks for the Neon direct URL, `APP_KEY` and `RESEND_API_KEY` (hidden) and only sends to an active admin.
Then check the inbox (and spam) and Resend → **Emails** for the delivery status. Common causes: domain not
verified in Resend, wrong `MAIL_FROM_ADDRESS`, revoked key.

## Admins

**Keep at least two active admins at all times**, each with two-step sign-in, so one can always recover the other.
Admins without two-step sign-in can only reach My Profile until they set it up.

**Add an admin:** Users → **Invite user**, role **Admin**. They set their own password from the emailed link
(48 hours, single use), then must set up two-step sign-in. Confirm it shows "On" for them.

**Recover an admin:**
- Forgot password: "Forgot password?" on the sign-in page.
- Lost phone: sign in with a recovery code. No codes left: **another admin** → Users → the admin → **Turn off
  two-step sign-in** (nobody can do this for themselves). They then set it up again on next sign-in.
- No admin can sign in at all: create a new one from your machine, then use it to recover the others:
  `powershell -ExecutionPolicy Bypass -File scripts\production-artisan.ps1 crm:create-admin`

## Offboard a user

1. Users → the user → **Deactivate**: choose who takes over their leads and follow-ups. Their sessions end
   immediately and they can no longer sign in (users are never deleted; history stays).
2. If they had access to any of these, remove it: Bitwarden collection, GitHub repo, Render, Neon,
   Cloudflare, Resend, UptimeRobot, Hostinger (DNS).
3. If they could see any secret (Bitwarden), rotate those secrets (below).

## Rotate secrets

Store every new value in Bitwarden (replace the old item) and nowhere else.

**APP_KEY** (only if leaked; it encrypts two-step secrets and staged imports):
1. `php artisan key:generate --show` → new key.
2. Render → Environment: set `APP_PREVIOUS_KEYS` to the **old** key and `APP_KEY` to the new one. Save
   (redeploys). Old encrypted data still decrypts through `APP_PREVIOUS_KEYS`; new data uses the new key.
3. Keep `APP_PREVIOUS_KEYS` set. Everyone is signed out once.

**Resend API key:** Resend → API Keys → create a new one (Sending access, `hybridmonks.com`) → Render
`RESEND_API_KEY` → wait for the deploy → "Check email" → delete the old key in Resend.

**Database password:** Neon → `monkcrm` → Roles → the app role (`neondb_owner`) → **Reset password**. Copy the
new **direct** URL → Render `DB_URL` and GitHub secret `NEON_DIRECT_URL` →
Bitwarden. The app errors until Render's redeploy finishes (about 5 minutes), so do it out of hours.

**R2 token:** Cloudflare → R2 → Manage API tokens → create a new token (same settings) → GitHub secrets
`R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` → run **Backup** manually → delete the old token.

**Backup age key:** generate a new pair, update both GitHub secrets, store it in Bitwarden. Keep the **old
private key** in Bitwarden until the last backup made with it has expired (12 months), or old backups
become unreadable.

## Leaked secret

A secret is leaked if it was pasted into chat, a ticket, email, a commit (even if deleted), or seen by someone
who shouldn't have it.

1. **Rotate it now** ("Rotate secrets"). Rotation is the fix; deleting the message or commit is not.
2. **Check for misuse:** Audit log (unknown sign-ins, exports), Neon → Monitoring (unusual connections),
   Resend → Emails/Logs (mail you didn't send), Cloudflare R2 metrics.
3. **If it was committed:** the pre-commit hook should have stopped it; find out why it didn't. Rewriting git
   history is optional after rotation (forks and clones keep the old commits anyway).
4. Tell the other admin what leaked, when it was rotated, and what you checked.

## Monthly checks (first working day)

- [ ] GitHub → Actions: **Backup** green every night, **Restore test** green this month (and scheduled runs
      still enabled).
- [ ] Neon → project → **Usage**: compute hours, storage and network transfer well under 100 CU-hours / 1 GB /
      5 GB. The nightly dump counts toward transfer (about the database size per night): if the database is
      over ~100 MB, plan the Launch upgrade.
- [ ] Neon → Branches: delete old `backup-before-migrate-*` / `before-restore-*` branches (10 max).
- [ ] UptimeRobot: monitor up, alert contacts still correct.
- [ ] Users: at least two active admins, both with two-step sign-in "On"; deactivate anyone who left.
- [ ] Audit log: skim exports, imports, role changes and failed sign-ins.
- [ ] Render: no failed deploys; Resend: no spike in bounces.
- [ ] `composer audit` and `npm audit` on `main`; update dependencies if anything is flagged.
