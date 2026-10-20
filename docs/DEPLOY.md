# Production deploy

Production is a **Render** web service (Docker, free tier) running the image built from `Dockerfile`, with a
**Neon** PostgreSQL project `monkcrm` (AWS Singapore, branch `production`, database `neondb`). DNS is at
**Hostinger**, email goes through **Resend**. Day-to-day operations are in [RUNBOOK.md](../RUNBOOK.md).

**Never paste a secret into chat, a ticket, a commit or a file in this repo.** Secrets live in two places only:
Render's environment settings (what the app uses) and Bitwarden (the copy we can recover from).

## Render service settings

| Setting | Value |
|---|---|
| Runtime | Docker (uses `Dockerfile` at the repo root) |
| Region | Singapore (same as Neon) |
| Branch | `main` (auto-deploy on push) |
| Health check path | `/healthz` |
| Custom domain | `crm.hybridmonks.com` |

## Environment variables

Set these under Render → the service → **Environment**. 🔒 = secret: also store it in Bitwarden.
Values shown are the exact value to use; "(secret)" means generate or copy it, never write it down elsewhere.

| Name | Value | Purpose |
|---|---|---|
| `APP_NAME` | `HybridMonks` | Product name in the UI, page titles and emails. |
| `APP_ENV` | `production` | Turns on production behaviour (HTTPS, HSTS, secure cookies, boot checks). Also set in the image. |
| `APP_DEBUG` | `false` | Never `true` in production: it would show stack traces and settings. The app refuses to boot if true. |
| `APP_KEY` 🔒 | (secret) | Encryption key. See "APP_KEY" below. The app refuses to boot without it. |
| `APP_URL` | `https://crm.hybridmonks.com` | Used in links in emails (invites, password resets). Must be `https://`; the app refuses to boot otherwise. |
| `LOG_CHANNEL` | `stderr` | Logs go to Render's log stream (the disk is wiped on restart). Also set in the image. |
| `LOG_LEVEL` | `warning` | Keeps logs short. Also set in the image. |
| `DB_CONNECTION` | `pgsql` | PostgreSQL. Also set in the image. |
| `DB_URL` 🔒 | (secret) | Neon **direct** connection string (Connect → "Connection pooling" **off**; host has no `-pooler`; ends `?sslmode=require`). The app refuses to boot without it, or with a pooled URL: Neon's pooler (PgBouncer, transaction mode) breaks PHP's prepared statements inside transactions (sign-in fails with "25P02 current transaction is aborted"). |
| `DB_SSLMODE` | `require` | Encrypted database connection. Also set in the image. |
| `SESSION_DRIVER` | `database` | Sessions survive restarts (no local disk). Also set in the image. |
| `SESSION_SECURE_COOKIE` | `true` | Session cookie is only sent over HTTPS. |
| `TRUSTED_PROXIES` | `*` | Render's load balancer terminates HTTPS; this makes client IPs (audit log, rate limits) and HTTPS detection correct. Safe because the container is only reachable through Render. |
| `MAIL_MAILER` | `resend` | Send email through Resend. |
| `RESEND_API_KEY` 🔒 | (secret) | Resend API key with **Sending access** for `hybridmonks.com` only. |
| `MAIL_FROM_ADDRESS` | `noreply@hybridmonks.com` | Sender of invites and password resets. The domain must be verified in Resend. |
| `MAIL_FROM_NAME` | `HybridMonks` | Sender name. |

Set by the image and normally left alone: `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `PORT=8080`.

The same direct URL is used by `scripts/migrate-production.ps1`
(pasted at a prompt) and by the backup workflow (GitHub secret).

## APP_KEY

Generate it once, on your own machine, from this repo:

```powershell
php artisan key:generate --show
```

It prints a value starting `base64:`. Paste it straight into Render (`APP_KEY`) and into a Bitwarden item
"HybridMonks – APP_KEY (production)". Do not save it anywhere else, and do not run `key:generate` without
`--show` against a production `.env`.

**It must never change.** It encrypts data that is stored in the database:

- every user's two-step sign-in secret and recovery codes (a new key locks everyone out of two-step sign-in),
- import data staged in `import_rows` and import error rows,
- session and CSRF cookies (a new key signs everyone out).

If the key is lost, that data cannot be decrypted. If it leaks, rotate it without losing data using
Laravel's `APP_PREVIOUS_KEYS` (RUNBOOK.md, "Rotate APP_KEY").

## Boot checks

With `APP_ENV=production` the app refuses to start (and Render shows the error in the deploy log) when
`APP_KEY`, `APP_URL` or `DB_URL` is missing, `APP_DEBUG` is true, or `APP_URL` is not `https://`.
The error names the setting, never its value (`App\Support\ProductionGuard`).

## DNS (Hostinger) and email domain (Resend)

DNS records are edited in Hostinger: hPanel → **Domains** → `hybridmonks.com` → **DNS / Nameservers** →
DNS records (nameservers `ns1/ns2.dns-parking.com`). In the *Name* field, enter only the part before
`.hybridmonks.com` (`crm`, `send`, `resend._domainkey`).

1. Render → service → **Settings → Custom Domains** → add `crm.hybridmonks.com`. Render shows a CNAME target
   (`<service>.onrender.com`).
2. Hostinger DNS → add `CNAME`, name `crm`, value the Render target.
   Render issues the HTTPS certificate once DNS resolves (minutes to an hour).
3. Resend → **Domains → Add domain** `hybridmonks.com` (region: Tokyo `ap-northeast-1` is closest) → add the
   records it lists (SPF `TXT`/`MX` on `send`, DKIM `TXT` on `resend._domainkey`) in Hostinger DNS → **Verify**.
   The root domain's MX/SPF belong to Google Workspace mail: leave them alone.
   Add a DMARC record if the domain has none: `TXT` `_dmarc` = `v=DMARC1; p=none;`.
4. Resend → **API Keys → Create**: permission **Sending access**, domain `hybridmonks.com`. Copy it into Render
   (`RESEND_API_KEY`) and Bitwarden.

## First deploy, in order

1. Neon: confirm the project is in Singapore and copy the **direct** connection string
   (Neon Console → Connect, "Connection pooling" off) into Bitwarden. Do not use the pooled one.
2. Render: create the web service (settings above), add every environment variable, deploy.
3. Run migrations and the base seeder from your machine: `scripts/migrate-production.ps1` (RUNBOOK.md).
   The app starts without tables but pages fail until this is done.
4. The same script offers `crm:create-admin` on the first run: create your admin.
5. Sign in, set up two-step sign-in (required for admins), invite a second admin.
6. Check email: `scripts\production-artisan.ps1 crm:mail-test you@hybridmonks.com` (RUNBOOK.md, "Check email").
7. Set up backups (GitHub secrets, `.github/workflows/backup.yml`) and monitoring (RUNBOOK.md).
