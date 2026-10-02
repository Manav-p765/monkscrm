# Build progress

| # | Step | Status |
|---|------|--------|
| 1 | Foundation | ✅ Done |
| 2 | Users / Settings / Audit log | ⬜ Not started |
| 3 | Leads CRUD / list / search / filters / duplicates | ⬜ Not started |
| 4 | Lead detail / notes / activities / follow-ups / timeline | ⬜ Not started |
| 5 | Dashboard | ⬜ Not started |
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

### Carried forward
- Step 2: audit helper, then write `auth.login`, `auth.login_failed`, `auth.logout`,
  `auth.password_reset`, `auth.2fa_*` events (hooks exist: Fortify/Laravel auth events).
- Step 3: replace `LeadPolicy` stubs, add the lead visibility scope.
- Step 7: enforce 2FA for admins; production deploy checklist (TRUSTED_PROXIES, SMTP, queue, backups).
