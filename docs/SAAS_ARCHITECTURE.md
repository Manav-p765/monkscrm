# SaaS architecture (phase S0)

Status: **plan only, no code changed.** Written 2026-10-20 against `c1cdf56`. Covers the move from a
single-company CRM (HybridMonks) to a multi-tenant SaaS for any small business.

Decisions already made (not reopened here):

- Multi-tenant SaaS with a new brand. Brand name and domain are not decided yet: this document writes
  `config('saas.brand')` (name) and `<brand-domain>` (domain, `config('saas.domain')`).
- One PostgreSQL database (Neon). Every tenant-owned row carries `tenant_id`; isolation is enforced in the app.
- Each tenant at `{slug}.<brand-domain>`. Platform admin on its own host (`admin.<brand-domain>`).
  `crm.hybridmonks.com` becomes tenant #1 `hybridmonks`, mapped as a custom domain.
- Billing later: Stripe (USD) + Razorpay (INR) behind one interface.
- Stack stays: Laravel 13, Blade + Alpine CSP, Postgres (Neon), FrankenPHP on Render.

Words used here: **tenant** = one customer business (the UI calls it a "workspace"); **platform** = what
we run for all tenants; **platform admin** = our own staff on the admin host.

---

## 0. Before S1 starts: open blockers from STATUS.md

The SaaS work makes these worse if they stay open, so they come first:

1. **Backups have never run** (`NEON_DIRECT_URL` and possibly other GitHub secrets missing). The S1 migration
   needs a restore point we know works.
2. **The repository is public** (`Manav-p765/monkscrm`). Once this is a product, the isolation design and its
   weak spots should not be readable by anyone. Make it private before S1 code lands.
3. **Email is not deliverable** (Resend DNS records missing). Invites and resets break for tenant #1 today.
4. **Render region** may be Oregon while Neon is in Singapore. Settle it before multiplying traffic.
5. **No CI.** Only backup workflows exist. S1 adds a test workflow (section 6). The isolation suite is only
   useful if it runs on every push.

---

## 1. Tenancy model

### 1.1 The `tenants` table (platform)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | Tenant #1 = `hybridmonks`. |
| `slug` | string(40) | `^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$`. Unique on `lower(slug)`. Reserved list (`admin`, `www`, `app`, `api`, `mail`, `status`, `help`, `billing`, `static`, `assets`, …) in `config('saas.reserved_slugs')`. Never reused after deletion (row is kept as a tombstone). |
| `name` | string(120) | Business name shown in the UI and emails. |
| `status` | string(20) | `active` / `suspended` / `pending_deletion` / `deleted` (PHP enum `TenantStatus`, no DB enum). |
| `plan` | string(40) | `free` for now; billing (S5) owns it. |
| `timezone` | string(64) | IANA name, validated against `DateTimeZone::listIdentifiers()`. Tenant #1 = `Asia/Kolkata`. |
| `default_currency` | string(3) | `App\Enums\Currency`. |
| `enabled_currencies` | jsonb | List of `Currency` codes. Moves here from the `enabled_currencies` / `default_currency` settings, because the platform needs them at signup and billing (S5) without loading tenant settings. |
| `custom_domain` | string(253), nullable | Lowercase host, e.g. `crm.hybridmonks.com`. Unique on `lower(custom_domain)`. One per tenant for now. If we ever need several, move to a `tenant_domains` table; the resolver already looks up by host, so only the lookup changes. |
| `custom_domain_verified_at` | timestamp, nullable | A custom domain only routes once verified (DNS TXT check, S6). Tenant #1 is set verified by the migration. |
| `lead_reference_seq` | bigint, default 0 | Per-tenant counter for `LD-000123` (section 3). |
| `created_at`, `updated_at` | timestamps | |
| `suspended_at`, `suspension_reason` | timestamp / string, nullable | `suspension_reason`: `billing` / `abuse` / `requested` / `other` (enum). |
| `deletion_requested_at`, `purge_after` | timestamps, nullable | Grace period before hard deletion (section 8.6). |
| `deleted_at` | timestamp, nullable | Set when purged; the row stays so the slug is never reused. |

### 1.2 Every current table, classified

**Tenant-owned**: gets `tenant_id bigint NOT NULL` + FK to `tenants` + the `BelongsToTenant` trait on its model.

| Table | Model | Notes |
|---|---|---|
| `users` | `User` | One user record per tenant (1.7). |
| `teams` | `Team` | |
| `lead_statuses` | `LeadStatus` | |
| `lead_sources` | `LeadSource` | |
| `tags` | `Tag` | |
| `leads` | `Lead` | |
| `lead_tag` | pivot (no model) | Gets `tenant_id` too, so composite FKs can check that the lead and the tag share a tenant, and so RLS can cover it. Written through `BelongsToMany` with `withPivotValue('tenant_id', …)`. |
| `lead_notes` | `LeadNote` | |
| `activities` | `Activity` | |
| `follow_ups` | `FollowUp` | |
| `imports` | `Import` | |
| `import_rows` | `ImportRow` | Staging rows contain encrypted contact data: it is the most sensitive table after `leads`. |
| `settings` | `Setting` | `key` unique per tenant. |
| `audit_logs` | `AuditLog` | Tenant audit log. Platform events go to `platform_audit_logs` instead. |
| `password_reset_tokens` | (broker) | PK becomes `(tenant_id, email)`. Needs a custom token repository (section 3). |
| `user_invite_tokens` | (broker) | Same as above. |

**Global (framework, shared, no tenant scope)**: no trait. Isolation comes from keys, payloads or columns
that the framework already checks.

| Table | Why it stays global | What changes |
|---|---|---|
| `sessions` | Laravel reads it by session id only. | Add `tenant_id` (nullable, indexed), filled by our session handler, checked on every request (2.6), used to revoke a tenant's sessions on suspension. |
| `cache`, `cache_locks` | Key-value store. | Every tenant key is prefixed `t{id}:` (2.9). |
| `jobs`, `job_batches`, `failed_jobs` | Queue tables. | The tenant travels inside the job payload (2.10). |
| `migrations` | Framework. | None. |

**Platform**: no `tenant_id`, only reachable from the admin host or console.

| Table | When |
|---|---|
| `tenants` | S1 |
| `platform_admins` | S3 (separate guard, section 4) |
| `platform_audit_logs` | S3 (append-only, like `audit_logs`) |
| `platform_sessions` | S3 (separate session table for the admin host) |
| `support_access_grants` | S3 |
| `plans`, `subscriptions`, `billing_events` | S5 |
| `webhook_events` | S5 (Stripe/Razorpay; tenant-owned once Meta Ads arrives, see 5) |

The CI check in section 6.3 fails when a table exists in the schema that is in none of these three lists.

### 1.3 How `tenant_id` is enforced (five layers)

**Layer 1: current tenant, set once per request.** `App\Tenancy\CurrentTenant` (a scoped singleton,
`app()->scoped()`) holds the tenant resolved from the host (section 2). `Tenant::current()` returns it,
`Tenant::currentOrFail()` throws. Nothing else in the app decides "which tenant".

**Layer 2: `BelongsToTenant` trait** (`app/Models/Concerns/BelongsToTenant.php`):

```php
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $current = Tenant::currentOrFail()->id;
            // Never trusted from input: tenant_id is not in any $fillable.
            if ($model->tenant_id !== null && $model->tenant_id !== $current) {
                throw new CrossTenantWrite($model);
            }
            $model->tenant_id = $current;
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw new CrossTenantWrite($model); // tenant_id never changes
            }
        });
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
```

`TenantScope::apply()` adds `where {table}.tenant_id = ?` (qualified, so joins stay correct). **It fails
closed:** with no current tenant it throws `NoTenantContext` instead of returning everything. The only way to
query across tenants is an explicit, greppable block:

```php
Tenancy::withoutTenant(fn () => Lead::query()->count()); // platform code only; allow-listed in CodeRulesTest
```

and `Tenancy::run($tenant, fn () => …)` for "act as this tenant" (console, jobs, tests).

**Layer 3: database `NOT NULL`.** `tenant_id` is `NOT NULL` on every tenant-owned table, with FK to
`tenants(id)` `ON DELETE RESTRICT` (tenants are purged by our own ordered code, never by cascade).

**Layer 4: composite foreign keys, so cross-tenant references cannot exist.** Each parent table gets a
unique index on `(tenant_id, id)`, and each child FK includes `tenant_id`:

```sql
-- parent
CREATE UNIQUE INDEX leads_tenant_id_id_unique ON leads (tenant_id, id);
-- child: a note's lead must be in the note's tenant
ALTER TABLE lead_notes ADD FOREIGN KEY (tenant_id, lead_id) REFERENCES leads (tenant_id, id) ON DELETE CASCADE;
```

Every current FK and its composite replacement:

| Child column | References |
|---|---|
| `leads.lead_status_id`, `leads.lead_source_id`, `leads.original_source_id` | `lead_statuses`, `lead_sources` |
| `leads.assigned_to`, `leads.created_by` | `users` |
| `leads.import_id` | `imports` |
| `lead_tag.lead_id`, `lead_tag.tag_id` | `leads`, `tags` |
| `lead_notes.lead_id`, `lead_notes.user_id` | `leads`, `users` |
| `activities.lead_id`, `activities.user_id` | `leads`, `users` |
| `follow_ups.lead_id`, `.assigned_to`, `.created_by` | `leads`, `users` |
| `imports.user_id` | `users` |
| `import_rows.import_id`, `import_rows.duplicate_lead_id` | `imports`, `leads` |
| `users.team_id` | `teams` |
| `audit_logs.user_id` | `users` |

Two details:

- **Nullable FKs** (`assigned_to`, `lead_source_id`, `team_id`, `import_id`, …) work with the default
  `MATCH SIMPLE`: when the column is null the constraint is skipped, and when it is set, both columns must
  match a row in the same tenant.
- **`ON DELETE SET NULL` cannot be composite** in SQLite, and in Postgres it would also null `tenant_id`
  unless written `SET NULL (column)` (PG 15+). The current `nullOnDelete()` FKs point at rows we never delete
  (users are deactivated, sources/statuses are disabled, imports are kept), so they become
  `ON DELETE RESTRICT`. The two real deletes stay cascades: `lead_tag` on tag delete, child rows on lead
  hard delete (only in tenant purge).

**Layer 5: Postgres Row-Level Security** as a second, independent layer (recommended, from S2; 1.4).

Plus the existing per-user rule: `Lead::visibleTo($user)` stays exactly as it is. Tenancy is the outer wall,
visibility the inner one. `scopeVisibleTo` also adds `$user->tenant_id === Tenant::current()->id` as a
fail-closed check (a user from another tenant sees nothing, even if a bug passed one in).

### 1.4 Postgres Row-Level Security: recommendation **yes, as layer 5, in S2**

How it would work:

```sql
ALTER TABLE leads ENABLE ROW LEVEL SECURITY;
ALTER TABLE leads FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON leads
  USING (tenant_id = current_setting('app.tenant_id', true)::bigint)
  WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::bigint);
```

The tenant middleware runs `select set_config('app.tenant_id', ?, false)` right after resolving the tenant
(bound value), and `Tenancy::run()` sets and restores it. If it is not set, `current_setting(..., true)`
returns null and every query returns **no rows** (fail closed).

**Why yes:**

- The app-level scope only protects Eloquent. This codebase has real bypasses today: `DB::table('sessions')`,
  `DB::table('lead_tag')`, `UniqueIgnoringCase` (`DB::table($table)`), every `Rule::exists()` /
  `unique:` rule (query builder, no global scopes), `toBase()` aggregates, and `AuditLog::subject()` calls
  `withoutGlobalScopes()`. Each must be fixed by hand in S1; RLS catches the next one we miss.
- The product holds other businesses' client data. One cross-tenant leak is the kind of incident a small
  SaaS does not survive. A second layer that does not depend on remembering a rule is worth a moderate cost.
- Our setup fits RLS well: **direct** Neon connections (no PgBouncer: we already refuse the pooler), and
  FrankenPHP in classic mode (`php_server`, not worker mode), so each request gets its own connection and a
  session-level `set_config` cannot leak to another request.

**Costs and how we handle them:**

| Cost | Handling |
|---|---|
| Table owners bypass RLS. Migrations run as the owner. | Two Neon roles: `crm_owner` (migrations, `migrate-production.ps1`) and `crm_app` (the app at runtime, no `BYPASSRLS`, `FORCE ROW LEVEL SECURITY` on every tenant table). `ProductionGuard` refuses to boot if the runtime role is the owner. |
| Platform admin and cross-tenant console jobs need to see everything. | They connect as `crm_platform` with `BYPASSRLS`, on a separate DB connection name used only by admin-host code and `Tenancy::withoutTenant()`. |
| SQLite cannot test it. | RLS tests run only in `composer test:pgsql`, which CI runs on every push (6.4). |
| Every new table needs a policy. | The schema check (6.3) fails when a tenant-owned table lacks `relrowsecurity` + `relforcerowsecurity` + a `tenant_isolation` policy. |
| A future move to a pooler (PgBouncer transaction mode) would break session `set_config`. | Then switch to `SET LOCAL` inside a per-request transaction. Recorded as a constraint in CLAUDE.md. |
| Query plans: the policy is one more `tenant_id = $1` predicate. | Same predicate the global scope already adds; indexes lead with `tenant_id` (1.5). |

**Why not in S1:** S1 already changes every table and the live data. Adding roles and policies in the same
release doubles the blast radius of the riskiest migration. S1 lays the ground (tenant_id on every
tenant table including pivots, `set_config` call in the middleware from day one so it is already exercised),
S2 switches the policies on.

### 1.5 Unique constraints and indexes that become per-tenant

| Today | Becomes |
|---|---|
| `users_email_lower_unique` on `lower(email)` | `(tenant_id, lower(email))` |
| `lead_statuses_name_lower_unique` | `(tenant_id, lower(name))` |
| `lead_sources_name_lower_unique` | `(tenant_id, lower(name))` |
| `tags_name_lower_unique` | `(tenant_id, lower(name))` |
| `teams_name_lower_unique` | `(tenant_id, lower(name))` |
| `leads.reference` unique | `(tenant_id, reference)` |
| `settings.key` unique | `(tenant_id, key)` |
| `password_reset_tokens` PK `email` | PK `(tenant_id, email)` |
| `user_invite_tokens` PK `email` | PK `(tenant_id, email)` |
| `import_rows (import_id, row_number)` | unchanged (`import_id` already belongs to one tenant) |
| (new) | `(tenant_id, id)` unique on every parent table, for the composite FKs |
| (new) | `tenants lower(slug)`, `tenants lower(custom_domain)` unique |

The matching PHP rules change at the same time: `UniqueIgnoringCase` adds `where tenant_id = ?`, and a new
`TenantRule::exists($table)` / `TenantRule::unique($table)` wraps `Rule::exists()` / `Rule::unique()` with the
tenant condition. `CodeRulesTest` forbids bare `Rule::exists(`, `Rule::unique(`, `'exists:` and `'unique:` in
`app/`.

**Hot indexes** get `tenant_id` as the leading column (the planner then reads one tenant's slice):
`leads (tenant_id, deleted_at, created_at)`, `leads (tenant_id, assigned_to)`, `leads (tenant_id, lead_status_id)`,
`leads (tenant_id, email_normalized)`, `leads (tenant_id, phone_normalized)` (duplicate check),
`follow_ups (tenant_id, assigned_to, status, due_at)`, `follow_ups (tenant_id, status, due_at)`,
`audit_logs (tenant_id, created_at)`, `audit_logs (tenant_id, action)`, `users (tenant_id, role, is_active)`,
`imports (tenant_id, created_at)`. Indexes that lead with `lead_id` (`lead_notes`, `activities`) are already
tenant-local and stay. Old single-column indexes that the new ones cover are dropped in the contract step.

### 1.6 Lead reference per tenant

Today `reference = 'LD-' . id` after insert, unique globally. In SaaS, the global id would leak platform volume
("LD-184233" for a 3-week-old tenant) and is not per-tenant. New rule: in `Lead::creating`,

```sql
UPDATE tenants SET lead_reference_seq = lead_reference_seq + 1 WHERE id = ? RETURNING lead_reference_seq
```

inside the lead's transaction (row lock on the tenant row, so two concurrent creates get different numbers),
then `reference = 'LD-' . str_pad($n, 6, '0')`. Tenant #1's existing references stay as they are, and its
counter starts at `max(leads.id)`, so new hybridmonks leads continue the same numbering. References stay
immutable (model rule unchanged). Route binding by `reference` is tenant-scoped through the global scope.

### 1.7 Can one person belong to several tenants? Recommendation: **one user record per tenant**

Each tenant has its own `users` rows. `ravi@example.com` can exist in tenant A and tenant B as two unrelated
users, with separate passwords, two-step sign-in, roles and sessions. Sign-in happens on the tenant's host
and only looks in that tenant.

| | One user per tenant (recommended) | Global identity + memberships |
|---|---|---|
| Isolation | Simple: a user row belongs to exactly one tenant, every FK to `users` is tenant-checked by layer 4. | `users` is global; `memberships(tenant_id, user_id, role)` carries role/active/team. Every check "is this user in this tenant" moves to the membership. More places to get wrong. |
| Current code | Almost unchanged: `role`, `is_active`, `team_id`, `alerts_seen_at`, invite flow, deactivation all stay on `users`. | Role, active flag, team and alerts state move to memberships; Fortify, policies, invites and 30+ queries change. |
| Password / 2FA | Per tenant. A tenant admin resetting 2FA only affects their tenant. | Shared: one tenant admin could reset 2FA for a person who also uses another tenant. Needs a rule that only the person or platform support can. |
| UX for people in several businesses (accountants, agencies, freelancers) | Separate sign-in per workspace (browsers keep per-host password entries, so it works). | One sign-in, a workspace switcher. |
| Later migration | Possible: add an `identities` table that links users by verified email, without moving roles. | n/a |

Small-business CRM users are almost always in one workspace. Take the simple model now. If demand appears,
add linked identities later without changing tenancy.

---

## 2. Tenant resolution and request lifecycle

### 2.1 Hosts

| Host | Serves |
|---|---|
| `{slug}.<brand-domain>` | Tenant app (all current routes). |
| Verified `tenants.custom_domain` (e.g. `crm.hybridmonks.com`) | Same tenant app. |
| `admin.<brand-domain>` | Platform admin only (section 4). No tenant routes. |
| `<brand-domain>` / `www.` | Marketing + "find your workspace" + signup (S4). No tenant data. |
| anything else | Plain 404 (no Laravel tenant routes run). |

Routes are split by `Route::domain()`: `routes/tenant.php` (today's `web.php`, plus Fortify, whose
`fortify.domain` stays null but whose routes only work once a tenant is resolved), `routes/admin.php` on
`config('saas.admin_host')`, `routes/platform.php` on the apex. `AccessControlTest` gains a check that every
tenant route carries the `tenant` middleware.

### 2.2 Middleware order (tenant hosts)

`ResolveTenant` runs **first**, before `StartSession`, so the session, cookies, rate limits and cache keys
already know the tenant:

1. **`ResolveTenant`**: host → tenant (2.3). Binds `CurrentTenant`, `set_config('app.tenant_id')`,
   `URL::forceRootUrl($tenant->url())`, sets `Context::add('tenant_id', …)`, sets the display timezone and
   mail from-name for this request.
2. `EncryptCookies`, `StartSession` (tenant-aware session handler, 2.6), `VerifyCsrfToken`.
3. `EnsureSessionBelongsToTenant` (2.6), `EnsureUserIsActive`, `RequireTwoFactorForAdmins`, `SecurityHeaders`.

### 2.3 Host → tenant lookup

- Normalize: lowercase, strip port and a trailing dot. Take the host from `$request->getHost()` only after
  `TrustProxies` is configured **without `X-Forwarded-Host`** (we only need `X-Forwarded-For` and
  `X-Forwarded-Proto`). Combined with "unknown host = 404", this blocks host-header poisoning of reset/invite
  links. Also enable `TrustHosts` for `*.<brand-domain>` + the verified custom domains.
- `{slug}.<brand-domain>` → match `lower(slug)`. Any other host → match verified `lower(custom_domain)`.
- Cached: `tenancy:host:{host}` → `{id, status}` for 60 seconds in the database cache. Invalidated by
  `Tenant::saved()` (status, slug, custom domain changes). The full `Tenant` model is loaded by id per request
  (one primary-key query); the cache only saves the host lookup. A suspension is effective within 60 s at
  worst, and immediately on the instance that made the change.
- **Unknown host or `deleted` tenant:** 404 page on the platform layout: "There's no workspace at this
  address" + link to the apex "Find your workspace". Same response for "never existed" and "deleted".
  Slugs are public (DNS), so this reveals nothing new.
- **`suspended`:** 403 page "This workspace is suspended. Contact your administrator." No sign-in form, no
  data, no tenant name beyond what the host already says. All its sessions were deleted at suspension. In S5,
  tenant admins of a billing-suspended tenant can reach a billing page only.
- **`pending_deletion`:** same as suspended, with the purge date for admins (S6).

### 2.4 Custom domains

- `crm.hybridmonks.com` is tenant #1's custom domain, set verified by the S1 migration.
- **Canonical host:** when a tenant has a verified custom domain, its slug host redirects there (`301`, path
  kept) and every generated link uses it (`$tenant->url()`). One host per tenant means one session cookie,
  one place users bookmark, and emails always point to the same host.
- New custom domains (S6): tenant admin enters a host → we show a `CNAME` to Render + a `TXT`
  `_<brand>-verify.<host>` token → background check marks it verified → we add it to Render through its API
  (custom domains are per service on Render) → Render issues the certificate. Until verified, it does not route.

### 2.5 Login per tenant

- Fortify's `authenticateUsing` already does `User::query()->where('email', …)`; with the global scope it only
  finds the current tenant's user. Made explicit anyway (`->where('tenant_id', Tenant::current()->id)`) and
  covered by a test.
- Same email in tenant A and B: signing in on A's host with B's password fails, exactly like an unknown email
  (same timing: the existing dummy `Hash::make` stays).
- The 2FA challenge stores `login.id` in the session; the session is per host (2.6) and the user lookup
  is tenant-scoped, so a pending challenge cannot move to another tenant.

### 2.6 Session cookie and session reuse

- **Host-only cookie with the `__Host-` prefix:** `SESSION_COOKIE=__Host-<brand>-session`, `SESSION_DOMAIN`
  unset (never `.<brand-domain>`), `Secure`, `Path=/`. Browsers then refuse to let any other subdomain set or
  overwrite it ("cookie tossing" from a compromised or malicious subdomain).
  Our forms send the `_token` field, so the `XSRF-TOKEN` cookie isn't needed: check in S1 that nothing reads it,
  then stop sending it (or rename it with the same prefix).
- **Session row knows its tenant:** a small `TenantDatabaseSessionHandler` (extends Laravel's
  `DatabaseSessionHandler`) writes `tenant_id` into `sessions`. `EnsureSessionBelongsToTenant` compares the
  session's tenant (also stored in the payload) with the resolved tenant; a mismatch invalidates the session
  and sends the person to sign-in. A stolen tenant-A cookie replayed on tenant B's host therefore gets nothing:
  different cookie jar in browsers, mismatch check on the server, and the guard's `User::find()` is
  tenant-scoped anyway (three independent reasons).
- Sign-in regenerates the session id (Fortify does already).
- `DeactivateUser` and `UserController::revokeSessions` keep `DB::table('sessions')->where('user_id', …)`
  (user ids are globally unique bigints) but add `where tenant_id` for clarity; they move to a
  `Sessions::revokeForUser()` helper, allow-listed in `CodeRulesTest`.
- Tenant suspension deletes `sessions where tenant_id = ?`.

### 2.7 CSRF

Laravel's token is per session and sessions are per host, so a token from tenant A is useless on tenant B.
Note: all `*.<brand-domain>` hosts are **same-site** for browsers (same registrable domain), so `SameSite=Lax`
does **not** stop a request from `a.<brand-domain>` to `b.<brand-domain>`. The CSRF token does, and so does the
host-only cookie (the attacker page cannot read or set B's cookie). We host no user-controlled content on any
subdomain, so a Public Suffix List entry is not needed; revisit if that ever changes.

### 2.8 Rate limiting

Every limiter key gets the tenant: `t{id}|…`. Plus global per-IP limits, because credential stuffing across
many tenants from one IP must also be slowed:

| Limiter | Today | SaaS |
|---|---|---|
| `login` | 5/min per email+IP, 30/min per IP | 5/min per `tenant+email+IP`; 30/min per `tenant+IP`; **60/min per IP across all tenants** |
| `two-factor` | 5/min per `login.id`+IP | unchanged (user ids are global), prefix tenant |
| `auth-forms` | 10/min per path+IP | per `tenant+path+IP`, plus 60/min per IP global |
| `live` | 60/min per user | unchanged (user id), prefix tenant |
| `throttle:10,1` invite, `20,60` import, `10,60` export | per user/IP, Laravel default key | named limiters with tenant prefix |
| (new) tenant-wide | none | e.g. 600 requests/min per tenant (noisy-neighbour cap, tuned in S7) |
| (new) emails per tenant | none | invites/resets: 50/hour per tenant (abuse of our sender reputation) |

### 2.9 Cache keys

Today the only app cache key is `crm.settings`. **In SaaS that key would serve tenant A's settings to
tenant B** (section 5, leak path 4). Rule: tenant data is cached only through `TenantCache` (a thin wrapper
that prefixes `t{tenant_id}:` and refuses to run without a tenant). `Settings` uses
`TenantCache::rememberForever('settings', …)`. The request memo (`app()->instance(...)`) stays, keyed by
tenant id too, because `Tenancy::run()` can switch tenants inside one process. `CodeRulesTest` forbids
`Cache::` in `app/` outside `TenantCache` and the tenancy resolver.

### 2.10 Queued jobs carry the tenant

There is no worker today; S2 adds one. The design is in place from S1 so nothing is written without it:

- `ResolveTenant` puts `tenant_id` into Laravel's `Context`. Laravel serializes `Context` into every queued
  job's payload automatically.
- A `TenantAware` job middleware (applied to every job through a base class `TenantJob`) reads it back and
  runs the job inside `Tenancy::run($tenant, …)`. A job without a tenant fails immediately (it does not run
  unscoped). Platform jobs extend `PlatformJob` instead, which runs in `Tenancy::withoutTenant()`.
- Jobs whose tenant was suspended or deleted since dispatch are dropped (logged with tenant id only).
- `defer()` callbacks (e.g. the reset email) run in the same request after the response, with the tenant
  still bound. Covered by a test anyway.
- Notifications that queue (`ShouldQueue`) go through the same path, and build URLs with `$tenant->url()`.

### 2.11 Console commands

Every tenant command takes a **required** `--tenant=<slug>` (base class `TenantCommand`, which resolves the
tenant, refuses suspended/deleted ones, and wraps `handle()` in `Tenancy::run()`). Without it, the command
fails before touching data. `crm:create-admin --tenant=hybridmonks`, `crm:mail-test --tenant=hybridmonks …`.
Platform commands (`tenants:create`, `tenants:list`, `tenants:suspend`, `tenants:export`, …) are separate
and audited to `platform_audit_logs`. `tinker` has no tenant: queries throw `NoTenantContext` until you call
`Tenancy::run()`, which is the safe default for ad-hoc production access.

### 2.12 Scheduled tasks

When a scheduler exists (S2), each tenant-level task is one platform command that iterates tenants and
dispatches one `TenantJob` per **active** tenant (`tenants:each <job>`), so one slow or failing tenant
doesn't block the rest and every unit of work has its own tenant context. Features keep working without
the scheduler ("overdue" stays computed at query time); the scheduler only adds things like reminder emails
and the stale `import_rows` purge.

---

## 3. What in the current code assumes a single company

File by file. "Scope" = gets the `BelongsToTenant` trait and so the global scope; listed only when something
beyond that is needed.

### Models and data

| File | Today | Change |
|---|---|---|
| All 13 models in `app/Models` (except `Tenant`) | No tenant | `use BelongsToTenant` on User, Team, LeadStatus, LeadSource, Tag, Lead, LeadNote, Activity, FollowUp, Import, ImportRow, Setting, AuditLog. `tenant_id` never in `$fillable`. |
| `app/Models/Lead.php` | `reference` = `LD-` + global id in `created`; `currency ??= Currency::default()` | Reference from the tenant counter in `creating` (1.6). `scopeVisibleTo` adds the tenant check on `$user`. `tags()` adds `withPivotValue('tenant_id', …)`. Route binding unchanged (scoped). |
| `app/Models/AuditLog.php` | `subject()` uses `->withoutGlobalScopes()` (to show archived leads) | **Leak path:** that also drops the tenant scope. Change to `->withTrashed()` where supported / `withoutGlobalScope(SoftDeletingScope::class)`. Add `actor_type` (`user` / `platform_admin` / `system`) and `platform_admin_id` (nullable) for support access (section 4). |
| `app/Models/ImportRow.php` | `purgeStale()` deletes stale rows of **all** tenants | Scoped to the current tenant when called from an import; a platform job purges all tenants explicitly via `withoutTenant()` once the scheduler exists. |
| `app/Models/FollowUp.php`, `LeadNote.php` | Route binding with `static::query()` + lead visibility | Scoped automatically; no change, covered by the isolation sweep. |
| `app/Models/Setting.php` | `key` unique globally; `getValue()` | Per tenant (scope + `(tenant_id, key)` unique). |
| `app/Models/User.php` | `otherActiveAdminsExist()`; invite link via `route()`; `sendPasswordResetNotification` deferred | Scoped. Links built from `$tenant->url()` (forced root URL). "Keep one active admin" becomes per tenant automatically. |
| (new) `app/Models/Tenant.php` | | `url()`, `displayTimezone()`, `enabledCurrencies()`, status helpers, cache invalidation. |

### Services and support

| File | Change |
|---|---|
| `app/Support/Settings.php` | Cache key `crm.settings` → `TenantCache` key `t{id}:settings`; memo keyed by tenant. `update()` unchanged otherwise (audit goes to the tenant's log). `enabled_currencies` / `default_currency` move to `tenants` columns. `SettingSeeder` defaults become `TenantDefaults`. |
| `config/crm.php` `permissions` | Stays global: the same role matrix for every tenant (Admin / Manager / Sales). The `setting`-driven widenings (`sales_can_view_all_leads`, `sales_can_view_unassigned`, `allow_duplicate_override`) are already per-setting, so per tenant once settings are. Per-tenant role editing is out of scope (open question 10). |
| `app/Support/Permissions.php` | `allows()` adds `$user->tenant_id === Tenant::current()?->id`. Gates generated as today. |
| `app/Services/Leads/LeadStatusChanger.php` | Works on loaded models; add an assertion that `$lead->tenant_id === $to->tenant_id` (and `$by`) before writing. Activities it creates get `tenant_id` from the trait. Composite FK is the backstop. Remains the single hook for Meta conversions later (job carries tenant). |
| `app/Services/Leads/LeadAssigner.php` | Same assertion for `$to` / `$by`. |
| `app/Services/Leads/LeadDuplicates.php` | The documented "look at ALL leads" exception must mean all leads **of this tenant**. The global scope does it; add an explicit test that a tenant-B email never matches in tenant A. |
| `app/Services/Imports/LeadImporter.php` | Lookups (`statuses`, `sources`, `tags`, `users`) and the duplicate query are scoped by the trait; "create unknown" status/source/tag gets `tenant_id` from the trait; staging rows (`import_rows`) carry `tenant_id`. Chunked runs (page asks for the next chunk) stay request-bound. |
| `app/Services/Leads/FollowUps.php`, `ActivityLogger.php` | Scoped via models; no logic change. |
| `app/Support/LeadStats.php` (dashboard + reports) | All queries start from scoped models; `selectRaw`/`toBase()` keep the scope (Eloquent applies scopes before `toBase()`). The `users.team_id` join is safe via the composite FK. Covered by the "counts" part of the isolation suite. |
| `app/Support/Alerts.php` | Scoped; "end of today" from the tenant's timezone. |
| `app/Support/LeadFilters.php` | `Rule::exists('lead_statuses'/'lead_sources'/'tags')` → `TenantRule::exists()`. **Today a tenant-B status id would pass validation**, then match nothing (empty list) or, worse, elsewhere be loaded. |
| `app/Support/Reorder.php` | `$model::query()` is scoped, so "the full order must contain every id" stays correct per tenant. |
| `app/Support/Audit.php` | Writes `tenant_id` (trait). Platform events (tenant created/suspended, support access) go to a new `PlatformAudit::log()`. Support access writes to **both** (section 4). |
| `app/Support/Dates.php` | `zone()` returns `Tenant::current()->timezone` (falls back to `config('saas.default_timezone')` on platform hosts). Everything that already goes through `Dates` is then per tenant. |
| `app/Support/Money.php`, `app/Enums/Currency.php`, `app/Rules/EnabledCurrency.php` | `Currency::enabled()`/`default()` read the tenant columns. No other change: amounts are already per lead and never converted. |
| `app/Rules/UniqueIgnoringCase.php` | Uses `DB::table($table)`: **must** add `where tenant_id = ?` (else tenant A can't create a "VIP" tag because tenant B has one, and it reveals that B has it). |
| `app/Support/ProductionGuard.php` | Add: `saas.domain`, `saas.admin_host` set; session cookie starts with `__Host-`; `SESSION_DOMAIN` empty; (S2) runtime DB role is not the owner. |
| `app/Support/LostConnectionDetector.php` | After a reconnect, `set_config('app.tenant_id')` must be re-applied (S2, RLS): hook the reconnect. |

### HTTP

| File | Change |
|---|---|
| `routes/web.php` | Becomes `routes/tenant.php` under `Route::domain()` + `tenant` middleware group. `/healthz` stays host-independent. |
| `app/Http/Requests/*` | Every `Rule::exists('users'|'teams'|'tags'|'lead_statuses'|'lead_sources', 'id')` → `TenantRule::exists(...)`: `AuditLogFilterRequest`, `DeactivateUserRequest`, `FollowUpListRequest`, `FollowUpRequest`, `LeadAssigneeRequest`, `LeadRequest` (incl. the `$activeOr` closure), `LeadStatusRequest`, `UserFields`, `UserListRequest`. `ActivityRequest` uses `config('crm.display_timezone')` directly → `Dates::zone()`. |
| `app/Http/Controllers/LeadBulkController.php` | `User::query()->find($value)` scoped: a tenant-B user id becomes "not found". Make that a validation error (it currently would silently **unassign**). |
| `app/Http/Controllers/LeadExportController.php` | Filename hard-codes `monks-crm-leads-` (breaks the APP_NAME rule today): `{tenant-slug}-leads-{date}.csv`. Query already starts from `Lead::visibleTo`. |
| `app/Http/Controllers/ImportController.php` | Template filename from tenant slug. |
| `app/Http/Controllers/ReportController.php` | CSV header row uses `config('app.name')` → tenant name. |
| `app/Http/Controllers/AcceptInviteController.php` | Broker `invites` uses the tenant-aware token repository. |
| `app/Http/Controllers/Settings/*` | Settings → General gains "Workspace": name, timezone, currencies (writes `tenants`, audited). Slug and custom domain are S6. |
| `app/Http/Middleware/SecurityHeaders.php` | HSTS `includeSubDomains` on `<brand-domain>` covers every tenant (good); add `preload` only once every subdomain is HTTPS-only (S7). CSP unchanged (`'self'` = the tenant host). |
| `app/Http/Middleware/RequireTwoFactorForAdmins.php` | Unchanged per tenant; a tenant setting "require two-step sign-in for everyone" fits here later. |
| (new) `ResolveTenant`, `EnsureSessionBelongsToTenant` | Section 2. |

### Auth (Fortify), invites, reset, 2FA

| Area | Today | Change |
|---|---|---|
| `FortifyServiceProvider` `authenticateUsing` | `User::where('email')` | Scoped + explicit tenant condition. Rate limiter keys (2.8). |
| Password reset (`password_reset_tokens`, broker `users`) | Laravel's `DatabaseTokenRepository` keys by email only | `TenantTokenRepository` (extends it, adds `tenant_id` to insert/select/delete), registered for both brokers. Without it, tenant B's reset for `ravi@…` would overwrite tenant A's token row. Reset link from `$tenant->url()`. |
| Invite links (`user_invite_tokens`, broker `invites`, 48 h) | Same | Same repository. `createInviteLink()` URL on the tenant host. |
| 2FA (TOTP) | Issuer = `config('app.name')` | Issuer = `"{brand} ({tenant name})"` so an authenticator app with two workspaces shows both clearly. Secrets stay encrypted with the one `APP_KEY`. |
| Remember-me | Disabled | Stays disabled. |

### Mail

| File | Change |
|---|---|
| `config/mail.php` from-name | Per request/job: From `"{tenant name} via {brand}" <notifications@mail.<brand-domain>>`, Reply-To the tenant's configured contact (default: first admin). Set in `ResolveTenant`/`TenantJob` through `Mail::alwaysFrom()` equivalent on the mailer, not by editing config at runtime in more than one place. |
| `app/Notifications/InviteUser.php`, `MailTest.php`, Laravel `ResetPassword` | Subject/body: "You're invited to {tenant name} on {brand}". Links on the tenant host. |
| `app/Console/Commands/MailTest.php` | `--tenant` required; still admins only. |

### Views

| File | Change |
|---|---|
| `partials/head.blade.php`, `errors/layout.blade.php` | Title `"{page} · {tenant name}"` on tenant hosts, `"{page} · {brand}"` elsewhere. |
| `components/layouts/app.blade.php` (sidebar name), `layouts/guest.blade.php`, `auth/*.blade.php` | Tenant name where it says the product today; small "on {brand}" footer. `<x-logo>` stays the brand logo until tenant logos exist (they need object storage, the disk is ephemeral). |
| `dashboard.blade.php:68` | Uses `Dates::zone()` already: becomes per tenant automatically. |

CLAUDE.md's "product name lives in `config('app.name')`" rule changes to: brand = `config('saas.brand')`,
workspace name = `Tenant::current()->name`. Done in S1 together with the code, so the rule and code agree.

### Seeders and commands

| File | Change |
|---|---|
| `DatabaseSeeder` (statuses, sources, tags, settings) | No longer seeds global rows. Its content moves to `App\Tenancy\TenantDefaults::seed(Tenant $t)`, run when a tenant is created (`tenants:create`, signup in S4). The individual seeders become thin callers that iterate `--tenant` or all tenants, keeping their "safe to re-run, never overwrite" behaviour. |
| `LeadStatusSeeder::slotAfter()` | `LeadStatus::query()->...->increment()` is scoped once run inside `Tenancy::run()`. |
| `DemoSeeder`, `PerformanceSeeder` | Create their own demo tenant(s) (`demo`, `demo-b`); still refuse production. Useful as the isolation fixture base. |
| `crm:create-admin` | `--tenant` required; email unique **per tenant** (`unique:users,email` → tenant rule). |
| (new) `tenants:create {slug} {name} --timezone --currency` | Creates tenant + defaults + prompts for its first admin. Platform-audited. |

### Deploy, runbook, backups

| File | Change |
|---|---|
| `docs/DEPLOY.md` | New env vars: `SAAS_BRAND`, `SAAS_DOMAIN`, `SAAS_ADMIN_HOST`, `SESSION_COOKIE=__Host-…`; `APP_URL` = the platform apex (links for tenants come from `Tenant::url()`); wildcard DNS + TLS steps; Render custom domain list. DNS is at Hostinger (fix the old "Vercel" mentions at the same time). |
| `RUNBOOK.md` | Every command gets `--tenant`. New sections: create / suspend / restore a tenant, support access, per-tenant export, per-tenant restore (8.5). "Keep two admins" becomes per tenant; platform admins have their own section. |
| `scripts/migrate-production.ps1` | `db:seed --force` becomes `tenants:seed-defaults` (all tenants, idempotent). The tinker `User::count()` check becomes `Tenancy::withoutTenant(...)` or `tenants:list`. |
| `scripts/production-artisan.ps1` | Allow-list adds `tenants:list`, `tenants:create`, `tenants:suspend`; `crm:mail-test` sets the brand sender. |
| `.github/workflows/backup.yml`, `restore-test.yml` | Unchanged mechanically (whole database = all tenants). Restore test also prints row counts **per tenant** (counts only) and checks `tenant_id is null` counts are 0. Bucket/file names drop "monks-crm". |
| (new) `.github/workflows/tests.yml` | SQLite + Postgres suites, the isolation suite, the schema checks (section 6). |

---

## 4. Platform super-admin

**Separate host:** `admin.<brand-domain>` only. Admin routes are registered only for that host; tenant hosts
never serve them, and the admin host never resolves a tenant. Optional (recommended at S7): Cloudflare
Access or an IP allow-list in front of the admin host.

**Separate identity:**

- Table `platform_admins` (name, email, password, 2FA columns, `is_active`, last login), model
  `PlatformAdmin`, guard `platform` (session driver), provider `platform_admins`. No relation to tenant
  `users`; a platform admin is never a tenant user and vice versa.
- Own session cookie (`__Host-<brand>-admin-session`) and own session table (`platform_sessions`), so
  tenant session code (`sessions.user_id`) and admin sessions can never be confused.
- Created only from the console (`platform:create-admin`, never seeded with a password, like today's rule).

**Mandatory 2FA:** a platform admin without confirmed 2FA can reach only the 2FA setup page (same pattern as
`RequireTwoFactorForAdmins`, but with no opt-out). Session lifetime 30 minutes idle, password confirmation
for every write action. WebAuthn/passkeys later (S7).

**What platform admins can do (S3):** list/search tenants (name, slug, status, plan, user count, lead count,
created), create, suspend/unsuspend (with reason), schedule deletion, resend a tenant admin's invite, see
platform audit log. They **cannot** see tenant data (leads, notes, users' details beyond name/email/role) by
default.

**Support access ("view as tenant"):**

1. The platform admin opens a tenant and clicks "Request support access", enters a **reason** (required,
   min. 20 characters, e.g. ticket number) and a duration (15 / 30 / 60 minutes, max 60).
2. A row in `support_access_grants` (`platform_admin_id`, `tenant_id`, `reason`, `mode` = `read`,
   `starts_at`, `expires_at`, `revoked_at`) is written, plus `platform_audit_logs` and the **tenant's own
   audit log** (`support.access_started`, actor "Support: {name}", reason). Tenant admins get an email.
3. The admin is sent to the tenant host with a single-use, 60-second signed token. The tenant host creates a
   **separate** support session bound to that grant; it is not a tenant `User` session and does not reuse
   anyone's account. A permanent banner says "Support access: read-only, ends at 15:42".
4. **Read-only:** a `SupportSession` middleware rejects every non-GET request and the export routes. Write
   support (fixing data on the tenant's behalf) needs the tenant admin to approve the grant in their own
   Settings (`mode` = `write`), S3 stretch or later.
5. Every page view during support access is written to the tenant's audit log (action
   `support.viewed`, path without query string). Grant expiry or revocation (by the platform admin or by any
   tenant admin from their audit log page) ends it immediately: the support session is checked against the
   grant on every request.
6. Tenant admins see the history under Audit Log, filterable by "Support".

---

## 5. Threat model: cross-tenant leak paths

Attacker: a legitimate user (any role) of tenant A, or someone with tenant A's session, trying to read,
change, count or infer tenant B's data. Also our own bugs.

| # | Leak path | Example in this codebase | Control | Test |
|---|---|---|---|---|
| 1 | **IDOR with another tenant's id** | `/notes/{id}`, `/follow-ups/{id}`, `/imports/{id}`, `/users/{id}`, `/settings/tags/{id}`, `/audit-log/{id}`, `/leads/LD-000123` (references now repeat across tenants) | Route model binding through scoped models → 404; composite FKs; RLS (S2) | Route sweep (6.1) with B's ids/references on A's host: always 404, B's rows unchanged |
| 2 | **Another tenant's id in a form body** | `assigned_to`, `tags[]`, `lead_status_id`, `team_id`, bulk `ids[]`, deactivation reassignment target | `TenantRule::exists()`; services assert same tenant; composite FKs reject the write | Body sweep: every validated id field filled with B's ids → validation error, no write |
| 3 | **Missing scope in a raw/builder query** | `DB::table('lead_tag')`, `DB::table('sessions')`, `UniqueIgnoringCase` (`DB::table`), `Rule::exists/unique`, `AuditLog::subject()->withoutGlobalScopes()` | Fail-closed global scope; `CodeRulesTest` forbids `DB::table`, `withoutGlobalScopes`, bare `Rule::exists/unique`, `Cache::` outside allow-listed files; RLS (S2) | Static checks + RLS tests: run each allow-listed raw query with tenant A set, assert no B rows |
| 4 | **Cache key collision** | `Settings::CACHE_KEY = 'crm.settings'` would give B the settings of whoever filled it first (visibility rules, currencies) | `TenantCache` prefix; request memo keyed by tenant | Two tenants with different settings in one process (`Tenancy::run` A then B): each reads its own |
| 5 | **Queued job without context** | (future) reminders, Meta webhooks, exports by email | `Context` → `TenantJob` middleware; jobs without a tenant fail; RLS returns nothing without `app.tenant_id` | Dispatch from tenant A, run the worker with no tenant bound: job sees only A; a job without tenant fails |
| 6 | **Session reuse across subdomains** | Cookie from `a.` sent to `b.` | `__Host-` cookie, session `tenant_id` check, tenant-scoped user lookup, CSRF per session | Copy A's session cookie to B's host → redirected to sign-in, session invalidated |
| 7 | **Export and report queries** | `LeadExportController`, `ReportController::export`, `LeadStats` aggregates (`selectRaw` + `toBase`) | Start from scoped models; filenames/headers from tenant | Sentinel check: B's sentinel strings never in A's CSVs; A's totals equal A-only hand counts |
| 8 | **Search** | Header live search, search page, user search | Scoped models; `Searchable` unchanged | Search for B's sentinel name/email on A's host → nothing |
| 9 | **Duplicate check** (deliberately ignores visibility) | `LeadDuplicates::find()`, importer duplicate query | Tenant scope still applies (it ignores *visibility*, never *tenancy*) | A creates a lead with B's email → no warning; duplicate-check endpoint with B's phone → "no match" |
| 10 | **Import staging** | `import_rows` (encrypted contact data), `ImportRow::purgeStale()` deletes all tenants' rows | `tenant_id` + composite FK to `imports`; purge scoped; platform purge explicit | A's import never sees or deletes B's rows; B's staging rows survive A's purge |
| 11 | **Webhooks** (Stripe, Razorpay in S5; Meta Ads later) | Public routes outside `auth`, no session | Tenant derived only from **our** stored mapping (Stripe customer id → subscription → tenant; Meta page id → integration → tenant), never from a host or a tenant id in the payload; signature verification; `webhook_events` with server-side tenant_id; billing webhooks only on the platform host | Webhook with valid signature for B's customer posted to A's host → ignored/404; forged tenant id in payload → ignored |
| 12 | **Counting / inference** | Unique-name rule says "already used" because B has it; global ids in `LD-`; timing | Per-tenant uniqueness and rule; per-tenant reference counter; same responses for "not found" and "not yours" (404, never 403) | Create a tag named like B's → allowed; route sweep asserts 404 (not 403) |
| 13 | **Audit log** | `subjectLabel()` loads the subject without scopes; filter by user id | Scoped subject; `TenantRule::exists('users')` on the filter | A's audit page with `?user=<B's id>` → validation error; A's entries never show B's subjects |
| 14 | **Links in emails / host header poisoning** | Reset/invite URLs built from the request host | Unknown hosts are 404; no `X-Forwarded-Host` trust; links from `$tenant->url()` | Forgot-password with spoofed `X-Forwarded-Host` → link uses the tenant host |
| 15 | **Support access misuse** (us) | A platform admin browsing tenant data | Grants with reason, expiry, read-only, tenant-visible audit, email to tenant admins | Expired grant → support session ends; write request in support session → 403; audit rows present in both logs |
| 16 | **Console and tinker** | Ad-hoc `Lead::query()` in production | `NoTenantContext` without `--tenant`/`Tenancy::run()` | Command without `--tenant` fails before any query |
| 17 | **Backups and restores** | Restoring the whole DB to fix one tenant rolls back all tenants | Per-tenant restore procedure via Neon branch + tenant export (8.5) | Restore test checks per-tenant counts |
| 18 | **Noisy neighbour** (availability, not secrecy) | One tenant's 20,000-row import or export loop slows everyone | Existing per-user limits + tenant-wide limits; import/export row caps per plan | Load test in S7 |

---

## 6. Isolation test strategy

### 6.1 The generic two-tenant suite (`tests/Feature/Tenancy/IsolationTest.php`)

**Fixture.** `TenantFixture::full(Tenant $t)` creates, inside `Tenancy::run($t)`, at least one row of
**every** tenant-owned model: users of all three roles (with 2FA where needed so admin pages load), a team,
statuses, sources, tags, leads (active + archived), lead_tag rows, notes (incl. a deleted one), activities,
follow-ups (pending, overdue, completed), an import with staging rows and an error report, settings,
audit logs, an invite token and a reset token. Every text field of tenant B contains a unique sentinel
(`ZZB-<uuid>` in names, emails `zzb-…@example.test`, phones `+99 …`). Tenant A gets its own sentinel.
The fixture asserts it covered every model returned by the model inventory (6.3), so a new model without
fixture data fails the suite.

**Model sweep (data layer)**: for every tenant-owned model, with tenant A bound:

- `count()` equals A's fixture count; `find(<B id>)` is null; `whereKey(<B ids>)->update([...])` and
  `->delete()` affect 0 rows; B's row hashes are unchanged afterwards.
- Creating with `tenant_id = B` throws `CrossTenantWrite`; changing `tenant_id` throws.
- With no tenant bound, any query throws `NoTenantContext`.
- On Postgres: raw `DB::select('select count(*) from <table>')` as the `crm_app` role returns A's count
  only (RLS, from S2), and an insert with B's `tenant_id` fails the policy check.
- Composite FKs: inserting an A row that references a B parent fails at the database level.

**Route sweep (HTTP layer)**: generated from `Route::getRoutes()`, not a hand list. For every tenant route,
for each role of tenant A, on A's host:

- **Parameterized routes** (`{lead}`, `{note}`, `{followUp}`, `{import}`, `{user}`, `{team}`, `{status}`,
  `{source}`, `{tag}`, `{auditLog}`): substitute B's id/reference for each parameter → response is 404 (never
  200, never 403), and B's data is byte-identical afterwards (row hash snapshot of every B table taken before
  the sweep, compared after).
- **Body ids**: for routes whose Form Request has an id field (`assigned_to`, `tags.*`, `ids.*`,
  `lead_status_id`, `lead_source_id`, `team_id`, `reassign_to`, filters `status`/`source`/`tag`/`user`/
  `assigned`/`team`), submit B's ids → 302 back with an error or 422; no write.
- **Every GET** (lists, dashboard, pipeline, reports, follow-ups, alerts, audit log, settings, search,
  live search, duplicate check, exports, error CSVs): the response body contains **no B sentinel**, and
  numbers that the page shows match A-only counts (dashboard cards, pipeline column counts and totals,
  report rows, alerts badge, list "Showing x of N").
- **Probing**: duplicate check and live search with B's sentinel email/phone/name → no match; creating a
  tag/status/source/team with B's names → allowed; invite a user with B's user's email → allowed.
- **Cross-host**: A's authenticated session cookie sent to B's host → sign-in page, session invalidated;
  sign-in on B's host with A's credentials → "These credentials do not match".
- **Unclassified route = failure:** a route that the sweep cannot classify (unknown parameter type, new body
  id field) fails with "add an isolation expectation for route X", so new routes are covered by design.

**Cross-cutting tests**: `Settings` and cache (leak path 4), queued job context (5), rate-limiter keys (A's
lockout doesn't lock B), password reset and invite tokens (B's reset doesn't touch A's token; A's token fails
on B's host), mail from-name and links per tenant, lead reference counter (two tenants both get `LD-000001`;
concurrent creates in one tenant get distinct numbers on Postgres), suspended tenant (no page, no sign-in,
sessions gone), console `--tenant` rule.

### 6.2 Where it runs

The isolation suite runs on **both** SQLite (fast, every local run) and Postgres (`composer test:pgsql`); RLS
and composite-FK tests are Postgres-only (`@group pgsql`). The existing 236 tests keep running unchanged in
a single default tenant created by `TestCase::setUp()` (bound via `Tenancy::run()` for the test and set on
the test host), so they don't need rewriting.

### 6.3 CI checks that fail on a missing trait or policy (`tests/Feature/Tenancy/TenancyRulesTest.php`)

1. **Model inventory:** scan `app/Models/*.php` with reflection. Every `Model` subclass must use
   `BelongsToTenant`, or be listed in `config('saas.platform_models')` (`Tenant`, `PlatformAdmin`,
   `SupportAccessGrant`, …). A new model in neither list fails.
2. **Schema inventory:** read the migrated schema (`Schema::getTables()` / `getColumns()` / `getForeignKeys()`).
   Every table must be in exactly one of `saas.tenant_tables`, `saas.global_tables`, `saas.platform_tables`.
   Each tenant table must have `tenant_id` NOT NULL, a FK to `tenants`, a `(tenant_id, id)` unique index if
   it has an `id`, and every FK to another tenant table must include `tenant_id`. On Postgres also: RLS
   enabled + forced + `tenant_isolation` policy (from S2).
3. **Code rules** (extends `CodeRulesTest`): no `DB::table(`, `DB::select(`, `withoutGlobalScope(s)(`,
   `Tenancy::withoutTenant(`, `Cache::`, bare `Rule::exists(`/`Rule::unique(`/`'exists:`/`'unique:` in `app/`
   outside an allow-list file per pattern. The allow-list is small and reviewed.
4. **Route rule** (extends `AccessControlTest`): every route is on exactly one host group; every tenant route
   has the `tenant` middleware; no admin route on a tenant host.
5. A `.github/workflows/tests.yml` runs `php artisan test` and `composer test:pgsql` (Postgres 17 service
   container) on every push and pull request; `main` is protected so a red run can't be merged.

---

## 7. Migration plan for the live database

Goal: the current production data becomes tenant #1 `hybridmonks` with **zero data loss** and at most a few
minutes of degraded service. Data volume is small (one company), so every step runs in seconds.

The current deploy rule applies: Render auto-deploys `main`, there is no shell, and
`scripts/migrate-production.ps1` runs migrations from a laptop **before** pushing, so every migration must
work with the code that is live at that moment. That forces an **expand → deploy → contract** sequence in two
releases.

### 7.1 Rehearsal (before touching production)

1. Neon → create branch `tenancy-rehearsal` from `production` (current point in time).
2. Run release 1 and release 2 migrations against the branch (the script's prompts accept any direct URL),
   then the verification queries (7.4), then point a local build of release 2 at the branch and run a smoke
   pass: sign in as each role, open every section, create a lead (reference continues numbering), export,
   import a 3-row file, undo it.
3. Run each `down()` against the branch (7.5) and confirm the old code works on the result.
4. Delete the branch afterwards (Free plan: 10 branches).

### 7.2 Restore point (both, on the day)

- `migrate-production.ps1` takes its usual encrypted `pg_dump` (or makes a Neon branch when `pg_dump` 18 / `age`
  are missing). For this migration do **both**: the script's backup **and** a Neon branch
  `pre-tenancy-<date>`. Keep the branch until release 2 has been live for a week.
- Precondition: the nightly backup workflow has had at least one green run and one green restore test
  (blocker 0.1). Do not start without it.
- Announce a 15-minute window to HybridMonks users; changes made during it are kept, nothing is frozen, but
  everyone is signed out once (new cookie name).

### 7.3 The steps

**Release 1: expand (compatible with the code that is live)**

Migration `2026_xx_01_create_tenants` + `2026_xx_02_add_tenant_id`:

1. Create `tenants`. Insert tenant #1: `slug = hybridmonks`, `name = 'Hybrid Monks LLP'`,
   `status = active`, `timezone = 'Asia/Kolkata'`, `enabled_currencies` / `default_currency` copied from
   the current settings rows, `custom_domain = 'crm.hybridmonks.com'`, verified now,
   `lead_reference_seq = coalesce(max(leads.id), 0)`.
2. On every tenant-owned table (1.2), plus `sessions`: add `tenant_id bigint NULL DEFAULT 1` with FK to
   `tenants`. The temporary `DEFAULT 1` means rows that the **old** code inserts between now and release 2
   still land in tenant #1. (On Postgres 11+ a constant default is a metadata-only change, so no table rewrite.)
3. Backfill explicitly anyway: `UPDATE <table> SET tenant_id = 1 WHERE tenant_id IS NULL` per table, in the
   migration, in one transaction.
4. Create the new indexes **concurrently-safe enough for our size**: `(tenant_id, id)` uniques, the new
   per-tenant uniques next to the old ones (both exist for now, the old ones are stricter, so the old code is
   unaffected), the hot `tenant_id`-leading indexes.

Run with `migrate-production.ps1`, then push release 1 (it contains only these migrations + the `Tenant`
model, nothing reads `tenant_id` yet). Verify (7.4).

**Release 2: tenancy code**

All of S1's code. Pushed only after release 1's migrations are live. It always writes `tenant_id`, resolves
`crm.hybridmonks.com` to tenant #1 through `custom_domain`, and can run while the columns are still nullable.

Before pushing, set the new env vars on Render (`SAAS_*`, `SESSION_COOKIE=__Host-…`). Turning off Render
auto-deploy for the day is recommended, so the env change and the push deploy once together ("Manual Deploy").

**Release 2 contract migration (run after release 2 is live and healthy)**

Migration `2026_xx_03_enforce_tenant_id` (in release 2's commit, but run by the script only after the deploy is
live; the old code is gone by then):

1. Re-check no nulls (abort with a clear message if any).
2. Drop the `DEFAULT 1`. `SET NOT NULL` on every tenant table.
3. Replace each FK with its composite version (1.3, layer 4); change the `nullOnDelete` ones to restrict.
4. Drop the old global uniques (`users_email_lower_unique`, the four `*_name_lower_unique`,
   `leads_reference_unique`, `settings_key_unique`), and the token tables' PK swap to `(tenant_id, email)`.
5. Drop single-column indexes now covered by tenant-leading ones.
6. Move `enabled_currencies` / `default_currency` settings rows: already copied to `tenants` in step 1; delete
   the two settings rows here (audited as a platform event).

Order note: release 2 must not depend on the contract step. Before it runs, the database is stricter (global
uniques) than release 2 needs, so nothing breaks. After it runs, the old code can no longer insert rows
(NOT NULL), which is why the contract step comes only after the old code is gone.

SQLite note (tests): composite FKs and PK swaps need table rebuilds on SQLite; Laravel's schema builder does
that, but it drops expression indexes on rebuild (the existing `users_email_lower_unique` workaround). The
migration recreates them after each rebuild on SQLite, as `step2_schema_additions` already does.

### 7.4 Verification (run after each step, numbers in, numbers out)

```sql
-- every tenant table: no rows without a tenant, and nothing outside tenant 1
select 'leads', count(*) filter (where tenant_id is null), count(*) filter (where tenant_id <> 1), count(*) from leads
union all ... -- one line per tenant table (script generated from config('saas.tenant_tables'))
-- references unchanged
select count(*), max(reference) from leads;
-- tenant counter continues numbering
select lead_reference_seq from tenants where id = 1;   -- = max(leads.id)
```

Row counts per table are captured **before** release 1 (the script prints them) and compared after each step:
they must be identical. Then sign in on `crm.hybridmonks.com`, open Leads, Pipeline, Reports, Audit Log, and
create and archive a test lead.

### 7.5 Rollback plan

| Point of failure | Rollback |
|---|---|
| Release 1 migration fails half way | It runs in a transaction per migration (Postgres DDL is transactional): nothing applied. Fix and rerun. |
| Release 1 live, something odd | Old code ignores `tenant_id`; leave the columns. `migrate:rollback` drops them if needed (tested `down()`). |
| Release 2 deploy is broken (before contract) | Render → Events → Rollback to the release-1 deploy. Database still has `DEFAULT 1`, nullable columns and global uniques: the old code works unchanged. Fix forward. |
| Contract step fails half way | Transactional: nothing applied. Release 2 keeps running on the expanded schema. |
| Release 2 must be rolled back **after** the contract step | First run the contract migration's `down()` (restores `DEFAULT 1`, drops NOT NULL, restores global uniques and simple FKs; safe because only tenant #1 exists), then Render rollback. Rehearsed in 7.1. |
| Data damage discovered later | Neon point-in-time restore to just before the step (6 h on Free, 7 days on Launch), or the `pre-tenancy-<date>` branch, or the encrypted dump. Same procedure as RUNBOOK "Restore a backup". |

Rule for S1: **no second tenant is created in production until the contract step has been live for a week.**
That keeps the contract `down()` valid as a rollback path for that week.

---

## 8. Infrastructure for SaaS

### 8.1 Wildcard DNS + TLS on Render

What Render documents (checked 2026-10-20, render.com/docs/custom-domains):

- Wildcard custom domains (`*.example.com`) are supported, with certificates issued and renewed by Render
  (Let's Encrypt).
- Three DNS records are needed: `CNAME *` → `<service>.onrender.com`, `CNAME _acme-challenge` →
  `<service-id>.verify.renderdns.com`, `CNAME _cf-custom-hostname` → `<service-id>.hostname.renderdns.com`.
- **"To use a wildcard domain, your root domain must also point to Render."** So the apex of the domain that
  carries the wildcard must also be on Render.
- Custom domains included per **workspace plan**: Hobby 2, Pro 15, Scale 25; each extra domain $0.25/month.
  The docs do not say whether a wildcard counts as one domain or whether it needs a paid instance type; the
  free instance type does list custom domains as supported.

What this means for us:

- Wildcard + apex = 2 domains, plus `crm.hybridmonks.com` = 3: over the Hobby workspace's 2 (≈ $0.25/month
  extra), or move the workspace to Pro. Each additional tenant custom domain (S6) is another domain on the
  service. At hundreds of custom domains, consider Cloudflare for SaaS (custom hostnames) in front instead.
- The apex requirement means the marketing site either runs on Render too (this app's platform routes, or a
  separate Render static site; must be verified that "point to Render" accepts another Render service), or the
  tenants live under a dedicated second level (`*.app.<brand-domain>`, apex `app.<brand-domain>`). Open
  question 1. **Verify with a throwaway domain in S2 before buying into a structure.**
- The brand domain's DNS host must support a wildcard `CNAME` and an apex record pointing to Render (Render's
  `A` record, or ALIAS/ANAME where offered). Check this for Hostinger before buying the domain there. If we move the brand domain's DNS to Cloudflare, keep the Render records **DNS-only (grey
  cloud)**, as Render's wildcard notes require.
- HSTS: we already send `includeSubDomains`. Every host under the brand domain must be HTTPS before we add
  `preload` (S7).

### 8.2 Render plan, worker and scheduler

Facts (render.com docs, 2026-10-20): free web services spin down after 15 minutes idle and share 750 instance
hours per workspace per month; **background workers and cron jobs have no free instance type**; Starter is
$7/month per service (512 MB, 0.5 CPU); cron jobs have a $1/month minimum each.

For SaaS (S2):

| Service | Plan | Why |
|---|---|---|
| Web | Starter ($7) | No spin-down (a cold start on someone's first click is not acceptable for paying tenants), zero-downtime deploys with health checks; Standard later when traffic needs it. |
| Worker | Starter ($7) | Runs `queue:work` **and** `schedule:work` in one container (a tiny `docker/worker-entrypoint.sh`), same image. Avoids a separate cron service. |
| Staging web | Free | A second environment with its own Neon branch, for rehearsals and isolation tests on real infrastructure. |

Also from S2: a **pre-deploy command** (paid instance types support it on Render) to run migrations in the
deploy instead of from a laptop. That retires the "run `migrate-production.ps1` before pushing" step, but
expand/contract still applies.

### 8.3 Neon plan and connections

Facts (neon.com, 2026-10-20): Free = 100 CU-hours/project/month, 1 GB storage, 6-hour restore window.
Launch = pay as you go ($0.106 per CU-hour, $0.35 per GB-month), restore window up to 7 days.
`max_connections`: 104 at 0.25 CU, 209 at 0.5 CU, 419 at 1 CU (7 reserved for Neon).

- **A queue worker keeps the database awake.** `queue:work` on the database driver polls every few seconds,
  so the compute never scales to zero: 0.25 CU × ~730 h ≈ 183 CU-hours/month, which is over the Free plan's
  100. **Moving to Launch is required as soon as the worker exists** (≈ $20/month at 0.25 CU always on,
  more with autoscaling). A 7-day restore window is also the right minimum for a paid product.
- Connections: classic FrankenPHP opens one connection per concurrent request; web + worker + scheduler at
  Starter sizes stay far below 97. We keep **direct** connections (no pooler) because of the prepared
  statement problem already documented, and because RLS uses session settings.
- Roles: `crm_owner` (migrations), `crm_app` (runtime, RLS enforced), `crm_platform` (`BYPASSRLS`, admin host
  and platform jobs) from S2.
- Autoscaling max 0.5 CU at first, alert on spend (Launch has spending alerts).

### 8.4 Email per tenant

- **Now (S1–S5): one shared sender.** `notifications@mail.<brand-domain>` (Resend, SPF/DKIM/DMARC on the
  `mail` subdomain), From name `"{tenant name} via {brand}"`, Reply-To the tenant's contact. One verified
  domain, one reputation to protect: per-tenant send limits (2.8), bounce and complaint webhooks from Resend
  that pause a tenant's sending if its bounce rate spikes.
- Tenant #1 can keep `noreply@hybridmonks.com` as a per-tenant sender override (it is already verified, once the
  missing DNS records are added), which is also the prototype for:
- **Later (S6+): custom sending domains per tenant** via Resend's domains API (tenant adds DKIM/SPF records,
  we verify). Check Resend's plan limit on number of domains before promising it.

### 8.5 Backups covering all tenants

- The nightly encrypted `pg_dump` already covers every tenant (one database). Restore test adds per-tenant
  count checks. Retention today: 30 daily + 12 monthly.
- **Restoring one tenant without rolling back the others** (a tenant deleted their own data): restore the backup
  or a Neon point-in-time branch into a separate branch → `tenants:export --tenant=x` from the branch →
  `tenants:import --tenant=x --replace` into production (S6 tool, inside a transaction, platform-audited). Until
  that tool exists, the only restore is whole-database, which affects every tenant; write that in the terms.
- Retention vs deletion: a deleted tenant's data lives on in backups until they expire. With 12 monthly backups
  that is up to a year: either shorten monthly retention (e.g. 3 months) or state it in the privacy policy
  (open question 9).

### 8.6 Per-tenant export and deletion

- **Export (S6):** `tenants:export --tenant=x` and a tenant-admin button produce a ZIP of CSVs (leads with
  tags, notes, activities, follow-ups, users without secrets, statuses/sources/tags, audit log), built in a job
  and stored encrypted in object storage (R2) with a short-lived signed download link (the local disk is
  ephemeral, so the file cannot live there). Audited in both logs.
- **Deletion (S6):** tenant admin requests it → `pending_deletion`, signed out everywhere, 30-day grace in which
  a platform admin can restore → purge job deletes rows in FK order, per table in batches, inside
  `Tenancy::run()`. The tenant's `audit_logs` are deleted with it (the one exception to append-only, recorded
  in `platform_audit_logs` with row counts, never content). The `tenants` row stays as a tombstone (slug never
  reused).

---

## 9. Phases S1–S7

### S1: Tenancy foundation (no signup, no billing)

**Delivers:** `tenants` table and model; `BelongsToTenant` + fail-closed scope on all 13 models; `tenant_id`
on all tenant tables including pivots and token tables; composite FKs; per-tenant uniques and hot indexes;
host resolution (slug subdomain + custom domain) with unknown/suspended handling; `__Host-` session cookie,
session tenant check, tenant-prefixed cache and rate limits; tenant-aware password and invite brokers;
per-tenant settings, timezone, currencies and lead references; mail from-name and links per tenant; `Context`
+ `TenantJob` plumbing (used by tests; no worker yet); `TenantCommand` with `--tenant`; `tenants:create`,
`tenants:list`, `tenants:suspend` (console only); `TenantDefaults`; `set_config('app.tenant_id')` call (no
policies yet); isolation suite + CI checks + tests workflow; live migration of HybridMonks to tenant #1; CLAUDE.md,
DEPLOY.md, RUNBOOK.md updated.

**Acceptance:** all existing tests pass in a default tenant; the isolation suite passes on SQLite and Postgres
for every route and model; the inventory checks fail when a model/table/route is added without classification
(proved by a deliberately failing commit on a branch); production runs as tenant #1 on `crm.hybridmonks.com`
with identical row counts and references; a second tenant created on **staging** (or a Neon branch) at
`<slug>.<brand-domain>` works end to end and sees nothing of tenant #1.

**Risks:** missing one raw query or validation rule (mitigated by static checks and the sweep; RLS in S2);
migration on live data (rehearsal, restore point, expand/contract, tested `down()`); everyone signed out once;
wildcard DNS not ready yet, so S1's second-tenant test uses a staging host or a hand-added subdomain.

### S2: Infrastructure + database second layer

**Delivers:** Render Starter web + worker (queue + scheduler), staging service, pre-deploy migrations; Neon Launch,
three DB roles, **RLS on every tenant table**; brand domain with wildcard DNS/TLS (verified on a throwaway domain
first); version endpoint (`APP_VERSION` from the git SHA, STATUS.md asked for it); `tenants:each` for scheduled
tenant jobs; stale import purge as a platform job.

**Acceptance:** RLS tests pass on Postgres (raw queries as `crm_app` see one tenant; no `app.tenant_id` = no rows);
`ProductionGuard` refuses the owner role at runtime; any `{slug}.<brand-domain>` gets a valid certificate; a
queued job dispatched in tenant A runs in tenant A on the worker.

**Risks:** RLS policy mistakes locking out a tenant (staging first, feature flag to fall back to the owner role
for one deploy); reconnect after Neon idle drops `set_config` (hook `LostConnectionDetector`); cost goes from $0
to roughly $35–45/month (Render 2× Starter + Neon Launch + domains).

### S3: Platform super-admin + support access

**Delivers:** admin host, `platform_admins` + guard + mandatory 2FA, tenant list/create/suspend/schedule deletion,
platform audit log, support access grants (read-only, reason, ≤ 60 min, visible and revocable by tenant admins,
email to tenant admins).

**Acceptance:** tenant routes 404 on the admin host and vice versa; a tenant user can't sign in on the admin host
and the reverse; support sessions can't write or export, end at expiry, and every view is in the tenant's audit log.

**Risks:** the support session path is a deliberate cross-tenant door: it gets its own isolation tests and a
security review before release.

### S4: Self-serve signup and onboarding

**Delivers:** signup on the apex (business name, slug with availability check and reserved list, timezone and
currency from country, first admin with email verification), `TenantDefaults` seeding, onboarding checklist
(invite team, import leads), "find your workspace" by email (emails the list of workspace links, never shows
it on screen), abuse controls (rate limits, disposable-email block, CAPTCHA-free honeypot + per-IP limits under
our CSP).

**Acceptance:** a stranger can create a workspace and reach the dashboard in under two minutes; slugs can't
collide or take reserved names; signup can't enumerate existing emails or workspaces.

**Risks:** spam signups using our sender reputation (send limits per new tenant, verification first);
slug squatting.

### S5: Billing (Stripe USD + Razorpay INR)

**Delivers:** `BillingProvider` interface with Stripe and Razorpay drivers; plans and limits (users, leads,
imports per month); checkout, customer portal, invoices; signed webhooks into `webhook_events` (idempotent,
tenant from our mapping only); trial, dunning, grace, suspension for non-payment (billing-only page for admins).

**Acceptance:** both providers pass sandbox end-to-end tests (subscribe, renew, fail, cancel, refund);
replayed or forged webhooks change nothing; plan limits enforced server-side.

**Risks:** two providers with different subscription models (keep our own subscription state as the source of
truth); GST/tax invoices for INR; webhook endpoint is public.

### S6: Tenant lifecycle and data rights

**Delivers:** tenant settings for slug change (with redirect from the old one for 90 days) and custom domain
self-serve (TXT verification, Render API); per-tenant export (ZIP via R2 signed link); deletion with 30-day grace
and purge; per-tenant restore tool (`tenants:export` / `tenants:import --replace`); optional custom sending domain.

**Acceptance:** export contains every tenant row and nothing from others; purge leaves zero rows with that
`tenant_id` in every table (checked by the schema inventory) and a platform audit entry; a restore of one
tenant from a branch leaves other tenants unchanged.

**Risks:** purge is irreversible (grace period, platform approval, backup retention disclosed); custom domain
takeover if a tenant's DNS still points to us after leaving (remove from Render on purge/domain change).

### S7: Brand, hardening and launch

**Delivers:** final brand name/domain/logo through `config('saas.brand')`; marketing pages, terms, privacy policy,
DPA, sub-processor list (Render, Neon, Resend, Cloudflare, Stripe, Razorpay); external penetration test focused
on tenant isolation; load test with 50 synthetic tenants; HSTS preload; WebAuthn for platform admins; admin host
behind Cloudflare Access or IP allow-list; status page; runbook for incidents including "suspected cross-tenant
leak" (who, how to contain, how to notify).

**Acceptance:** pen test finds no cross-tenant issue (or all fixed and retested); p95 page time under load within
target; all docs published.

**Risks:** launch pressure cutting the pen test; brand domain decision late (it blocks S2's wildcard work, so
decide early).

---

## 10. Open questions (with recommendations)

1. **Brand name and domain, and whether tenants sit directly under it.** Render requires the wildcard's root
   domain to point to Render too. *Recommendation:* decide the domain before S2. Prefer `{slug}.<brand-domain>`
   with the apex served by this app's platform routes on Render (one service, one deploy); if the marketing site
   must live elsewhere, use `{slug}.app.<brand-domain>`. Verify on a throwaway domain first.
2. **Canonical host for HybridMonks:** `crm.hybridmonks.com` or `hybridmonks.<brand-domain>`? *Recommendation:*
   keep `crm.hybridmonks.com` canonical; the slug host redirects to it.
3. **When does the "HybridMonks" product name stop being the product?** *Recommendation:* in S1 introduce
   `config('saas.brand')` with the value "HybridMonks" until the brand exists, and show the tenant name in
   the UI. The switch to the new brand is then one env var.
4. **RLS:** approve it as S2's second layer (section 1.4)? *Recommendation:* yes.
5. **Paid infrastructure timing:** Render Starter ×2 + Neon Launch ≈ $35–45/month. *Recommendation:* at the start
   of S2, not before; S1 runs on today's free setup.
6. **Who are the platform admins?** *Recommendation:* exactly two people, each with 2FA, from S3. Nobody uses a
   tenant admin account for platform work.
7. **Support access consent:** notify-only for read access, or tenant approval every time? *Recommendation:*
   read-only access with notification and instant revocation; write access only with tenant admin approval.
8. **Data residency:** everything is in Singapore (Render + Neon). *Recommendation:* stay single-region and say
   so in the privacy policy; revisit only when an EU customer requires it.
9. **Backup retention vs deletion promises:** 12 monthly backups keep deleted tenants' data for a year.
   *Recommendation:* reduce monthly retention to 3, state "deleted data leaves backups within 90 days".
10. **Per-tenant permissions:** should tenant admins edit the role matrix? *Recommendation:* no for S1–S7. Keep
    the global matrix and the existing setting-driven widenings; add "require two-step sign-in for everyone"
    as a tenant setting (STATUS.md already flagged it).
11. **One user record per tenant** (1.7): confirm. *Recommendation:* yes; linked identities later only if asked for.
12. **Lead reference format:** keep `LD-000123` for all tenants, or a per-tenant prefix? *Recommendation:* keep
    `LD-`, number per tenant. A prefix setting is easy to add later because references are generated in one place.
13. **Public ids for other records** (notes, follow-ups, imports, users use global sequential ids in URLs): they
    reveal platform-wide volume, nothing per tenant. *Recommendation:* accept for now (all lookups are
    tenant-scoped and 404 otherwise); switch new public routes to ULIDs if a customer asks.
14. **Repo visibility:** *Recommendation:* make it private before S1 code lands (blocker 0.2).
15. **Tenant #1's sender:** keep `noreply@hybridmonks.com` or move HybridMonks to the shared brand sender?
    *Recommendation:* keep it as tenant #1's sender override; it's the test case for custom sending domains.
16. **Meta Ads integration (V2 track) vs SaaS order:** *Recommendation:* build it after S2 (it needs the worker),
    tenant-aware from day one (integration rows tenant-owned, webhook tenant from the page-id mapping).
