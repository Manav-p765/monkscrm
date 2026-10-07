# Build progress

| # | Step | Status |
|---|------|--------|
| 1 | Foundation | ✅ Done |
| 1c | PostgreSQL + Docker readiness | ✅ Done |
| 1d | Design audit + currency groundwork | ✅ Done |
| 2 | Users / teams / settings / currencies / audit log | ⬜ Not started |
| 3 | Leads CRUD / list / search / filters / duplicates / bulk actions / Kanban | ⬜ Not started |
| 4 | Lead detail / notes / activities / follow-ups / timeline / alerts bell | ⬜ Not started |
| 5 | Dashboard / Reports | ⬜ Not started |
| 6 | Import / export | ⬜ Not started |
| 7 | Hardening / 2FA enforcement / deploy | ⬜ Not started |

## Step 1: Foundation (done)

- Laravel 13 + Fortify + Tailwind 4 + Alpine (CSP build) via Vite.
- Full schema for all 7 steps: users, lead_statuses, lead_sources, tags, leads, lead_tag,
  lead_notes, activities, follow_ups, imports, audit_logs, settings (+ framework tables:
  password_reset_tokens, sessions, cache, jobs).
- Eloquent models with relationships, casts, explicit `$fillable`. Lead reference codes (`LD-000123`),
  email/phone normalization, immutable `original_source_id`, append-only `AuditLog`.
- Seeders: statuses, sources, tags, settings. `DemoSeeder` (refuses production).
  `php artisan crm:create-admin`.
- Auth: login/logout, forgot/reset password (30 min links, identical response for unknown emails),
  no registration, no remember-me, 5/min login throttle per email+IP, inactive users blocked and
  logged out mid-session, last login time/IP recorded, password rules (12+ chars, mixed case, number,
  breached-password check).
- My Profile: change password, enable/confirm/disable 2FA (TOTP), recovery codes.
- Authorization: role helpers, gates, `LeadPolicy` stub.
- Security baseline: CSP with nonces, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy,
  HSTS (production), X-Robots-Tag, robots.txt, `no-store` on authenticated pages, HTTPS forced
  in production, boot refuses `APP_DEBUG=true` in production, custom error pages, 404 fallback.
- Layout shell with role-aware sidebar; placeholder pages for unbuilt sections (gated).
- UI foundation (step 1b): product name "Monks CRM" (from `APP_NAME`), UI rules + glossary in
  CLAUDE.md, light single-accent theme at a 15px base, and shared components (layout/page header,
  button, input/select/textarea, more details, badge, table, empty state, flash, confirm dialog,
  more menu). Existing pages rebuilt with them. Submit buttons show a loading state.
- Feature tests: 54 passing.

## Step 1c: PostgreSQL + Docker readiness (done)

- Hosting decided: Docker on Render + Neon PostgreSQL (Singapore). Constraints recorded in CLAUDE.md "Hosting".
- PostgreSQL is the default connection. Migrations use jsonb, string columns + PHP enum casts (no DB
  enums), explicit FK indexes, and case-insensitive unique indexes on lower(email) and lookup names.
- `Searchable` trait: the one case-insensitive search helper (ILIKE on pgsql, LIKE on sqlite).
- `composer test:pgsql` runs the full suite on Postgres (`.env.testing.pgsql`, gitignored).
  60/60 tests pass on both SQLite and PostgreSQL 17.
- Production `Dockerfile` (FrankenPHP, PHP 8.4, non-root, listens on `$PORT`, caches built at start),
  `.dockerignore`. Image built and smoke-tested locally against Postgres (login → dashboard).

## Step 1d: Design audit + currency groundwork (done, 2026-10-07)

- Google AI Studio prototype cloned into `design/` (reference only; nested git repo, gitignored
  secrets/build output, excluded from the Docker image).
- Findings, feature map, data model gaps, security issues and the frontend recommendation (keep
  Blade + Alpine) are in `docs/DESIGN_AUDIT.md`; screenshots in `docs/design-screens/`.
- **Scope:** every prototype feature is in the MVP, except the demo-only items listed in
  `docs/DESIGN_AUDIT.md` "Excluded from the MVP". Decisions D1–D12 are all settled (end of the audit).
- **Currency groundwork:** `leads.deal_value` (bigint, minor units) + `leads.currency` (ISO 4217,
  not null); `App\Enums\Currency` (fixed list USD, INR, EUR, GBP, AUD, CAD; `enabled()`, `default()`);
  settings `enabled_currencies` (default USD, INR) and `default_currency` (default USD); new leads get
  the default currency; `App\Support\Money` (formatting with Indian grouping for INR, decimals hidden
  when zero, per-currency totals, never summed across currencies) and `<x-money>`;
  `App\Rules\EnabledCurrency` (enabled list, but a lead keeps a since-disabled currency).
- Tests: 65 passing + 1 skipped (SQLite). `composer test:pgsql` was not run: the local PHP
  (Herd Lite) has no `pdo_pgsql`.

## MVP plan (all prototype features)

### Step 2: Users / teams / settings / currencies / audit log
- Audit helper; auth events (see "Carried forward").
- Users: list, create user via emailed set-password link (never an admin-typed password), edit role,
  team, job title, phone; deactivate/reactivate; send password reset link. Manager: read-only
  directory. Read-only role & permissions table on the Users page.
- Teams (needs migration: see audit G2 / teams do not limit lead visibility, D8).
- Settings: statuses (add, rename, color, description, reorder, enable/disable, won/lost, delete only
  if unused), sources (add, enable/disable; system sources protected), tags (add, color, delete),
  "Sales can view all leads", duplicate rules (read-only), default status and default source,
  **currencies** (enable from the fixed list; default must be enabled; can't disable the default).
  Every settings change is audit-logged, including currency changes.
- My profile: name, phone, job title (password + 2FA already done).

### Step 3: Leads + Kanban
- Visibility scope + real `LeadPolicy`.
- List: header search, quick filters (all, assigned to me, follow-up due/overdue, unassigned, urgent &
  high), filters (status, assigned to, source, priority, tag, **currency**), sort, 25 per page, empty
  states. Global header search with the `/` shortcut.
- Create/edit form: all prototype fields (incl. address line 2), **deal value + currency selector**
  (enabled currencies only, default `default_currency`; validated with `EnabledCurrency`), tags,
  initial note. Duplicate detection with override for managers/admins.
- Bulk: change status, assign/unassign, add tag, archive (confirm, admin). Assignments audited.
- **Kanban board:** columns = active statuses; drag & drop + step buttons; status change writes an
  activity; column totals one line per currency (`<x-money :totals>`).

### Step 4: Lead detail + follow-ups + alerts bell
- Lead detail: inline status/priority/assignee, quick actions, timeline, notes, follow-ups, contact
  details.
- Activities: call, email, meeting, SMS, other (+ outcome). Follow-up types add demo, check-in,
  proposal; date + time; mark done and **reopen**.
- Follow-ups page: all pending / overdue / due today / upcoming / completed.
- **Alerts bell** in the header: overdue + due today, computed per request.

### Step 5: Dashboard + Reports
- Dashboard: stat cards, pipeline strip, follow-up queue with Done, recent activity. Money shown
  per currency, never summed across currencies.
- **Reports:** won value, active pipeline, average deal size (each per currency), win rate, funnel by
  status, rep scorecard with follow-up compliance. Visibility-scoped and gated.

### Step 6: Import / export
- 6-step import wizard (upload, read, map, validate, duplicates, preview) with DB staging (audit G9),
  import history, template download. Mapping includes deal value and a **currency column** (falls back
  to `default_currency`; values outside the enabled list are row errors).
- Export CSV (filtered or selected; formula-escaped; includes deal value + currency); audited.

### Carried forward
- Step 2: audit helper, then write `auth.login`, `auth.login_failed`, `auth.logout`,
  `auth.password_reset`, `auth.2fa_*` events (hooks exist: Fortify/Laravel auth events).
- Step 3: replace `LeadPolicy` stubs, add the lead visibility scope.
- Step 7: enforce 2FA for admins; production deploy checklist (TRUSTED_PROXIES, SMTP, queue, backups).
