# Status check: 2026-10-15

Read-only check before V2 planning. Nothing in the app, Render, Neon or DNS was changed. Sources: the docs
in this repo, the code at `3d099b1`, the live site, public DNS and the public GitHub API.

**Health in one line:** the code is green and the live site is hardened and up, but it is **not ready
for real data**: backups have never succeeded, and email can't be delivered because the Resend DNS
records don't exist.

---

## 1. Code and deploy state

| Check | Result |
|---|---|
| Branch | `main`, working tree clean |
| Local vs origin | `main` == `origin/main` == `3d099b1` ("Database: reconnect after Neon idle shutdown", 12 Oct 12:42 IST). No unpushed commits, no uncommitted files. |
| Commit in production | **Can't tell for sure:** the app has no version endpoint. Production has the HybridMonks logo (`/images/logo.png` → 200), so it is at least `75a2487`. Its `robots.txt` was last modified 12 Oct 12:59 IST, 17 minutes after `3d099b1` was pushed, so it is **most likely `3d099b1`**. |
| `php artisan test` (SQLite) | **236 tests: 235 passed, 1 skipped** (Postgres-only test), 2,567 assertions |
| `composer test:pgsql` (PostgreSQL 17) | **236/236 passed**, 2,569 assertions |
| TODO / FIXME / XXX in `app/`, `resources/`, `routes/`, `database/` | **None** |

Local machine note: drive **C: has 0 GB free**. Docker Desktop's CLI hung during this check, and anything
writing to the temp folder on C: failed (the tests were run with `TEMP` on D:). Free up space on C:.

## 2. Live site checks (https://crm.hybridmonks.com)

| Check | Result | Evidence |
|---|---|---|
| HTTPS works | PASS | `/login` 200 over TLS (Cloudflare in front of Render) |
| HTTP → HTTPS | PASS | `http://…/login` → `301 Location: https://crm.hybridmonks.com/login` |
| HSTS | PASS | `strict-transport-security: max-age=31536000; includeSubDomains` |
| Security headers + CSP on `/login` | PASS | CSP with a per-request nonce (`default-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`, `base-uri 'self'`), `x-frame-options: DENY`, `x-content-type-options: nosniff`, `referrer-policy: same-origin`, `permissions-policy: camera=(), microphone=(), geolocation=()`, `x-robots-tag: noindex, nofollow` |
| Same headers on a 404 | PASS | identical header set on `/definitely-not-a-page-xyz` (404) |
| Debug off: 404 | PASS | our error page ("404 · …"), no stack trace / exception / vendor paths |
| Debug off: forced error | PASS (419) | POST `/login` without a CSRF token → our 419 page, no trace. A 500 can't be forced without changing the app, so it wasn't tested. |
| `/.env`, `/.git/config`, `/storage`, `/vendor`, `/composer.json`, `/phpinfo.php` | PASS | all 404 (also `/storage/logs/laravel.log`, `/vendor/autoload.php`, `/.env.example`, `/index.php/../.env`) |
| `/healthz` | PASS | 200, `text/plain`, body `ok`, **no Set-Cookie** |
| Login rate limit | PASS | non-existent email: attempts 1–5 rejected normally, attempt 6 → "Too many sign-in attempts. Please wait 47 seconds and try again." Stopped there. |
| `robots.txt` | PASS | `User-agent: *` / `Disallow: /` |
| `X-Robots-Tag` | PASS (pages) | present on every app response. Not on `/robots.txt` itself (served as a static file); harmless. |
| Assets from our domain only | PASS | `/login` loads only `https://crm.hybridmonks.com/build/assets/app-*.css`, `app-*.js`, `/images/*`, `/favicon.ico`; CSP `default-src 'self'` would block anything else |

Other findings:
- **Product name is wrong in production:** page titles say "Sign In · **Monks CRM**" and the session cookie
  is `monks-crm-session`. Render's `APP_NAME` is still `Monks CRM`; it should be `HybridMonks` (DEPLOY.md).
  Changing it renames the session cookie, so everyone gets signed out once.
- **Render region may be Oregon, not Singapore:** `crm.hybridmonks.com` → `monkscrm.onrender.com` →
  `gcp-us-west1-1.origin.onrender.com`. That origin name normally means US West. If so, every database
  query crosses the Pacific to Neon Singapore. Check Render → service → Settings → Region. (TTFB today:
  `/healthz` ≈ 0.25 s, `/login` ≈ 0.36 s.)
- **DNS is at Hostinger** (`ns1/ns2.dns-parking.com`), not Vercel as DEPLOY.md and RUNBOOK.md say.

## 3. Email (Resend)

DNS lookups (Google 8.8.8.8 and the authoritative `ns1.dns-parking.com` agree):

| Record | Type | Status |
|---|---|---|
| `resend._domainkey.hybridmonks.com` | TXT (DKIM) | **Missing** (name does not exist) |
| `send.hybridmonks.com` | MX | **Missing** |
| `send.hybridmonks.com` | TXT (SPF) | **Missing** |
| `_dmarc.hybridmonks.com` | TXT | Exists: `v=DMARC1; p=none; rua=mailto:hitesh.m@…` (fine as is) |
| `hybridmonks.com` | MX / TXT | Google Workspace mail (`smtp.google.com`, `v=spf1 include:_spf.google.com ~all`). Leave alone; Resend sends from the `send` subdomain, so the root SPF doesn't change. |

Until the three records exist and Resend shows the domain as **Verified**, invite and password-reset
emails from `noreply@hybridmonks.com` will be rejected by Resend.

**Records to add in Hostinger** (hPanel → Domains → hybridmonks.com → **DNS / Nameservers** → DNS records →
Add record). Copy the values from Resend → Domains → hybridmonks.com. In Hostinger's *Name* field, enter only the
part before `.hybridmonks.com`:

| Type | Name | Value (from Resend) | Priority |
|---|---|---|---|
| TXT | `resend._domainkey` | the DKIM key (`p=MIGf…`) | |
| MX | `send` | `feedback-smtp.<region>.amazonses.com` | 10 |
| TXT | `send` | `v=spf1 include:amazonses.com ~all` | |

Then click **Verify** in Resend, and run `scripts\production-artisan.ps1 crm:mail-test <your admin email>` (RUNBOOK
"Check email").

> **Reminder:** `hybridmonks.com` must be added as a domain in the **same Resend account** that issued the
> `RESEND_API_KEY` set in Render. A key from another account (e.g. a personal one) can't send from a domain
> verified elsewhere. If the domain doesn't exist in that account, add it there, and use the records that
> account shows (each account gets its own DKIM key).

## 4. Backups and monitoring

| Check | Result |
|---|---|
| `.github/workflows/backup.yml` on `origin/main` | Exists, workflow **active** |
| `.github/workflows/restore-test.yml` on `origin/main` | Exists, workflow **active** |
| Backup runs | **5 runs, all failed** (scheduled, 10–14 Oct). Each fails at step "Check secrets are set" with **"Secret for DATABASE_URL is not set."**, so `NEON_DIRECT_URL` is missing. The check stops at the first missing secret, so the others are unknown. |
| Restore test runs | **0 runs** (first scheduled run is 2 Nov). |
| Repo secret names | Couldn't list them: `gh` isn't installed here and the GitHub MCP connector failed to authenticate. Required (RUNBOOK): `NEON_DIRECT_URL` (**confirmed missing**), `BACKUP_AGE_PUBLIC_KEY`, `BACKUP_AGE_PRIVATE_KEY`, `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`. |
| Uptime monitoring | Not verifiable from here (UptimeRobot is a separate account). |

**Backups have never run successfully.** This is a go-live blocker. Right now the only protection is
Neon's 6-hour point-in-time restore.

The run history was readable without signing in because **the repository `Manav-p765/monkscrm` is public**
(see section 8, security).

## 5. Production configuration (names only)

From docs/DEPLOY.md. "Indirect" = what the live site's behaviour shows; "By hand" = check in Render → Environment.

| Variable | Verifiable? | What the live site shows |
|---|---|---|
| `APP_NAME` | Indirect: **WRONG** | Titles show "Monks CRM", cookie `monks-crm-session`. Should be `HybridMonks`. |
| `APP_ENV` | Indirect: OK | HSTS and `secure` cookies are only sent in production |
| `APP_DEBUG` | Indirect: OK | Error pages without traces; boot guard refuses `true` |
| `APP_KEY` | Indirect: OK | App boots (the guard refuses a missing key); encrypted cookies issued |
| `APP_URL` | Indirect: OK | Asset and form URLs are `https://crm.hybridmonks.com`; guard refuses non-https |
| `LOG_CHANNEL`, `LOG_LEVEL` | By hand | (image defaults `stderr` / `warning`) |
| `DB_CONNECTION`, `DB_URL` | Indirect: OK | Sign-in page writes a database session; guard refuses a missing or pooled URL |
| `DB_SSLMODE` | By hand | (Neon refuses non-SSL anyway) |
| `SESSION_DRIVER` | By hand | |
| `SESSION_SECURE_COOKIE` | Indirect: OK | Session cookie has `secure; httponly; samesite=lax` |
| `TRUSTED_PROXIES` | **By hand** | Can't be told apart from one client IP: the per-IP limit triggers either way, and HTTPS is forced in production regardless. Without `*`, the whole office shares one rate limit and the audit log shows proxy IPs. |
| `MAIL_MAILER`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | By hand, then `crm:mail-test` | Can't be tested until the Resend domain is verified |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `PORT` | Set by the image | |

## 6. Data (Neon)

**Not checked.** The Neon connector needs you to sign in again (`/mcp` in an interactive session). Still to
check: users by role and active, leads, imports, audit_logs counts, the latest `audit_logs.created_at`,
whether branch `pre-migration-2026-10-09` still exists, and compute-hours / storage against the Free plan
limits RUNBOOK lists (100 CU-hours/month, 1 GB storage, 5 GB egress). Run this check again after
reconnecting.

## 7. Feature coverage vs PRD and prototype

The PRD itself is not in this repo (DESIGN_AUDIT.md notes this). The rows below follow the PRD section
names in the brief, the prototype screens (DESIGN_AUDIT §3, §5) and the routes and code at `3d099b1`.

| Area | Status | Notes |
|---|---|---|
| Auth (sign-in, sign-out, reset, invites) | Done | Fortify; invite links (48 h, single use); no self-signup (by design); rate limits on all auth forms |
| Two-step sign-in | Partial | Required for admins. **Optional for Manager/Sales**, with no setting to require it for everyone |
| SSO (Google Workspace etc.) | Not built | The domain is on Google Workspace, so Google sign-in is the obvious option |
| Roles & permissions | Done | Admin / Manager / Sales; one permissions table (`config('crm.permissions')`) drives gates, policies and Settings → Permissions |
| Team-based visibility for managers | Not built | Decision D8: teams don't limit what managers see. Managers see all leads |
| Dashboard | Done | 8 stat cards, pipeline strip, follow-up queue, recent activity, sources; money per currency |
| Leads (create / edit / archive / restore) | Done | Archive = soft delete, admin only, with confirm dialog |
| Lead fields | Done (fixed set) | All prototype fields + deal value / currency + milestones |
| Custom fields | Not built | |
| Statuses | Done | Add, rename, colour, reorder, enable, default, milestone, description |
| Priority | Done | Low / Medium / High / Urgent, inline on the lead |
| Sources | Done | Default source; original source never changes |
| Tags | Done | Colour palette, bulk add |
| Manual creation + duplicate check | Done | Live duplicate warning; override for managers/admins |
| Duplicate merge | Not built | Detection only; no way to merge two leads |
| Lead list | Done | 25 per page, quick filters, bulk status / assign / tag / archive |
| Customizable list columns | Not built | Fixed columns |
| Search | Done | Header live search (`/` shortcut) + search page, visibility-scoped |
| Filters | Done | Status, assigned to, source, priority, tag, currency |
| Saved filter views | Not built | Filters live in the URL only (bookmarkable) |
| Sorting | Done | |
| Pipeline (Kanban) | Done | Drag & drop + "Move to…"; column money totals refresh only on reload |
| Lead detail | Done | Slide-over with its own URL; inline status / priority / assigned to; timeline |
| Activities | Done | Call, email, meeting, SMS, other + outcome; "no answer" outcomes don't count as contacted |
| Notes | Done | Author edits within 15 min; admin delete (audited) |
| Follow-ups | Done | Schedule, complete, reopen, reschedule, cancel; Follow-ups page; alerts bell |
| Follow-up reminders by email | Not built | No scheduler on Render free; only the in-app bell |
| Tasks module (PRD §21) | Not built | Excluded from the MVP (the prototype has no screen for it) |
| Import | Done | 6-step wizard, CSV/XLSX, staging encrypted, duplicates, undo within 24 h, history |
| Export | Done | Lead CSV (filters, selected columns, formula-safe, audited) + report CSV. CSV only, no XLSX |
| Reports | Done | Win rate, won value, funnel (cumulative), by status/source/assigned to/team, sales scorecard |
| Users & teams | Partial | Invite, edit, deactivate with reassignment, reactivate, teams, manager directory. **Users can't edit their own name / phone / job title on My Profile** (carried forward since Step 2; only admins can) |
| Settings | Done | Statuses, sources, tags, security & rules, currencies, permissions. No Integrations section yet |
| Audit log | Done | Append-only, admin page with filters and details |
| Mobile layout | Partial | Collapsible sidebar below `lg`; laptop/tablet checked, phones never tested in a browser |
| Prototype screens (sign-in, shell, dashboard, list, pipeline, form, detail, follow-ups, import, reports, users, settings, profile) | Done | Remaining differences are listed in REVIEW.md §4 (deliberate or minor) |

## 8. Pending items

### Blockers before real users or data
1. **Backups have never succeeded.** Add the GitHub secrets (`NEON_DIRECT_URL` is confirmed missing), then
   run **Backup** and **Restore test** by hand until both are green (RUNBOOK "Backups → One-time setup").
2. **Email can't be delivered.** Add the three Resend DNS records in Hostinger and verify the domain (section 3).
   Without this, invites and password resets don't arrive, so nobody new can join.
3. **Manual click-through of every interactive screen on the live site** (REVIEW.md "Would not go live without" #1).
   Nothing in the docs records that it was done after the deploy. There are no browser tests.
4. **Confirm `TRUSTED_PROXIES=*`** in Render (REVIEW.md #2). It can't be verified from outside.
5. **Two active admins, both with two-step sign-in.** Not verified (Neon not checked).

### Setup you must do by hand
1. **GitHub secrets:** repo → Settings → Secrets and variables → Actions → New repository secret, for each of
   `NEON_DIRECT_URL`, `BACKUP_AGE_PUBLIC_KEY`, `BACKUP_AGE_PRIVATE_KEY`, `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`,
   `R2_SECRET_ACCESS_KEY`, `R2_BUCKET` (values: RUNBOOK steps 1–3). Then Actions → Backup → Run workflow;
   when green, Actions → Restore test → Run workflow.
2. **Resend DNS:** section 3. Then `crm:mail-test`.
3. **Render → Environment:** set `APP_NAME` = `HybridMonks` (everyone is signed out once). While there,
   check `TRUSTED_PROXIES`, `SESSION_DRIVER`, `DB_SSLMODE`, `LOG_*` and the `MAIL_*` values against DEPLOY.md.
4. **Render → Settings → Region:** confirm whether it's Singapore. If it's Oregon, a region can't be changed in place:
   create a new Singapore service with the same settings and env vars, move the custom domain, delete the old one.
5. **UptimeRobot** keyword monitor on `/healthz` (RUNBOOK "Monitoring") if not done.
6. **Neon:** autoscaling max 0.5 CU on `production`; delete old `backup-before-migrate-*` branches.
7. **Free up space on C:** on the dev machine.
8. **Reconnect the Neon connector** and fix the GitHub connector (its auth header is malformed) or install `gh`, then
   rerun sections 4 and 6.

### Bugs and rough edges
- Production shows "Monks CRM" (wrong `APP_NAME`).
- DEPLOY.md and RUNBOOK.md say DNS is at Vercel; it's at Hostinger. Update the docs.
- No version endpoint: production's commit can only be guessed. A build-time `APP_VERSION` (git SHA) shown to
  admins, or in a header, would fix that.
- Kanban column money totals don't update after a drag until reload.
- My Profile can't edit own name, phone or job title.
- `X-Robots-Tag` missing on static files (e.g. `robots.txt`); cosmetic, since `robots.txt` disallows all.

### Decisions waiting on you
- Keep the GitHub repo public, or make it private (recommended; see below).
- Render region (if Oregon: move to Singapore?).
- Require two-step sign-in for all users, or keep it admin-only?
- Upgrade to Render Starter (no spin-down, background worker, cron) and/or Neon Launch (longer restore window,
  spending alerts)? Several V2 items depend on it.
- Should teams limit what managers see (revisit D8)?
- V2 scope and order (section 9), and whether Meta Ads starts in parallel.

### Security follow-ups
- **Public repository:** `github.com/Manav-p765/monkscrm` is public. History was scanned and holds no secrets,
  but the code, RUNBOOK, infrastructure names and workflow history are visible to anyone. Recommend making it
  private (Settings → General → Danger zone → Change visibility). Actions keeps working on the free plan's
  private-repo minutes.
- **Key rotations:** the Resend key leaked earlier (PROGRESS Step 7) must be confirmed revoked in Resend. Any
  secret ever pasted into chat should be rotated (RUNBOOK "Leaked secret").
- **Neon connector access:** the Claude Neon connector has write and destructive tools. Scope it to the
  `monkcrm` project read-only (or a read-only role) before reconnecting, or remove it when not needed.
- **Render workspace ownership:** make sure the Render service, Neon project, Cloudflare R2, Resend, GitHub repo
  and Hostinger DNS are owned by a company account (not a personal one), with a second admin on each.
- **Resend account:** the API key in Render and the verified domain must be in the same company-owned account.
- **Bitwarden:** age key held by two people (RUNBOOK).

## 9. V2 candidates

Ranked by value for the effort. S ≈ days, M ≈ 1–2 weeks, L ≈ several weeks.

| # | Feature | Why it matters | Effort | Dependencies |
|---|---|---|---|---|
| 0 | Platform: Render Starter + a queue worker (or `defer()`), version endpoint, browser tests | Unblocks reminders, Meta webhooks and anything async; browser tests catch the JS breakage REVIEW.md found | M | Render Starter plan (paid) |
| 1 | **Meta Ads integration** (own track, below) | Leads arrive automatically with campaign attribution; Qualified/Won sent back to Meta to optimise ad spend | L | Queue worker, Meta app + review, privacy policy URL, public webhook route |
| 2 | Follow-up reminders by email (daily digest + overdue) | Follow-ups are the core workflow; today the reminder only shows when someone opens the app | M | A scheduler: Render cron job (paid) or a GitHub Actions cron calling a signed endpoint; verified Resend domain |
| 3 | Duplicate merge | Imports and manual entry create duplicates; today they can only be archived | M | None |
| 4 | Saved filter views | Sales and managers re-build the same filters daily | S | None |
| 5 | Two-step sign-in for all users (setting) + Google SSO | Lead data is client data; the domain already uses Google Workspace | S (2FA) / M (SSO) | Google Cloud OAuth client for SSO |
| 6 | Team-based visibility for managers | Needed once there is more than one sales team | M | Decision on D8; careful change to the visibility scope + tests |
| 7 | Tasks module (PRD §21) | To-dos not tied to a lead's follow-up | M | Design (prototype has no screen) |
| 8 | Customizable list columns | Different roles care about different columns | S–M | None |
| 9 | Custom fields | Per-client data without migrations | L | jsonb column + settings UI + import / export / filter support |
| 10 | Mobile layout pass | Sales use phones between meetings | M | Browser tests |
| 11 | Small items: My Profile name/phone/title, live Kanban totals, XLSX export | Rough edges from the MVP | S | None |

### Meta Ads track (from `Meta_Ads_CRM_Integration_Technical_Requirements.docx`)

Build order from the spec: 1 connect Meta (OAuth, asset selection, encrypted tokens) → 2 lead capture (webhook,
retrieval, mapping, dedupe) → 3 pipeline (already built) → 4 conversion events (QualifiedLead, Customer) →
5 campaign reporting → 6 hardening.

**Already supported by the current schema and code:**
- Pipeline stages match the spec: statuses carry milestones, and `leads` already has `first_contacted_at`,
  `qualified_at`, `meeting_booked_at`, `proposal_sent_at`, `won_at`, `lost_at`, each stamped **once**
  by `LeadStatusChanger`. That is the "don't resend on edit" idempotency rule the spec asks for, and
  `LeadStatusChanger` is the single hook point for queuing conversion events.
- `deal_value` + `currency` = the spec's `customer_value`.
- Sources and immutable `original_source_id` (add a "Meta Ads" source), `assigned_to` via `LeadAssigner`
  (alerts the assignee), normalized `email_normalized` / `phone_normalized` for secondary duplicate matching,
  the importer's create/update services, `activities.meta` (jsonb) for timeline entries, the append-only audit
  log (who connected/disconnected), Laravel encrypted casts for tokens, a permissions table for "integrations
  are admin-only", and Settings for configurable API versions.

**Missing:**
- Attribution storage: `meta_lead_id` (unique index: primary dedupe rule), page / form / campaign / ad set / ad
  IDs and names, `form_answers` (jsonb), `last_synced_at`, as columns or a `lead_attributions` table (better for
  multiple accounts later).
- Tables: `integrations` (encrypted tokens, connected assets, status), `meta_forms` (enable per form),
  `webhook_events` (inbox for idempotency, retries, dead letters), `conversion_events` (stable event ID, status,
  response, retry count).
- A **public** webhook route outside the `auth` group: needs a CLAUDE.md exception, an `AccessControlTest`
  allowlist entry, CSRF exclusion, Meta signature verification (`X-Hub-Signature-256`) and the verify handshake.
- A queue worker (the spec requires fast acknowledgement + background processing) or a `defer()` design with a
  retry sweep.
- Settings → Integrations → Meta Ads screen; an integration health panel (last sync, failed events, retry).
- Campaign dashboard spend data (Marketing API insights): new.
- Business side: Meta Business verification, developer app, app review for lead permissions, privacy policy and
  terms URLs on a public page.
