# Monks CRM: project rules

**Monks CRM** is the internal lead-management CRM for Hybrid Monks LLP, deployed at https://crm.hybridmonks.com.
The product name lives in exactly one place, `APP_NAME` → `config('app.name')`. Never hard-code
"Monks CRM" in views, emails or code; always use `config('app.name')`.
It holds client lead data. **Security is the top priority:** no data may be reachable
without authentication and correct authorization. Keep code clean and practical; do not
over-engineer and do not add packages unless clearly needed.

## Stack (fixed)
- Laravel 13, PHP 8.4 in production (8.3+ supported), **PostgreSQL** (Neon in production, 17 locally).
  SQLite in-memory for the fast default test run only; `composer test:pgsql` runs the same suite on Postgres.
- Blade + Alpine.js (**CSP build**, `@alpinejs/csp`) + Tailwind CSS 4 via Vite. No SPA, no React/Vue, no public JSON API.
- Laravel Fortify for auth (login, logout, password reset, 2FA). Our own Blade views in `resources/views/auth`.
- PHPUnit for tests (`php artisan test`).

## Non-negotiable rules
1. **Authorization:** every controller action that touches leads, users or settings goes through a
   Policy or Gate (`$this->authorize()`, `Gate::authorize()`, or `can:` route middleware).
   Never rely on role checks done only in Blade; hiding a link is not security.
2. **Lead visibility:** all lead queries go through the single visibility scope (built in step 3).
   Never `Lead::all()`, `Lead::query()->...` or `DB::table('leads')` without it.
3. **Validation:** validate all input with Form Request classes (`app/Http/Requests`).
4. **SQL:** no raw SQL with string interpolation. Use Eloquent or the query builder with bindings.
5. **Logging:** never log passwords, tokens, 2FA secrets/codes, or full lead contact details
   (email, phone, address). Log IDs/references instead.
6. **Audit log:** every sensitive action (auth events, user/role changes, settings changes,
   exports, imports, lead deletes/assignments) writes to the audit log via the helper (step 2).
   `audit_logs` is append-only: never update or delete rows.
7. **Progress:** keep `docs/PROGRESS.md` updated after each step.
8. **Text search:** always use the search helper, `->search($term, ['col1', 'col2'])` from
   `App\Models\Concerns\Searchable` (ILIKE on PostgreSQL, LIKE on SQLite, wildcards escaped).
   Never hand-write `like` / `ilike` / `lower(...)` comparisons: Postgres `LIKE` is case-sensitive.
9. **Money:** never format money directly (no `number_format`, no hand-written symbols). Use
   `App\Support\Money` / `<x-money>`. Amounts are integer minor units (cents/paise) with a per-lead
   `currency` (`App\Enums\Currency`). Never add amounts in different currencies together and never
   convert: totals are one entry per currency (`Money::totals()` + `<x-money :totals>`). Validate
   currencies with `App\Rules\EnabledCurrency`.

## Hosting

- **Production:** Laravel in a Docker container (`Dockerfile`, FrankenPHP) on a **Render** web service
  (free tier for now), database on **Neon** (serverless PostgreSQL). Both in **Singapore**.
- Constraints every feature must respect:
  - **The filesystem is ephemeral.** Never store anything on local disk that must survive a restart.
    Sessions, cache and rate-limiter data live in the database. Uploaded import files are processed and
    discarded within the request.
  - **The app can be restarted or spun down at any time.** No in-memory state between requests.
  - **Logs go to stderr** (`LOG_CHANNEL=stderr` in production), never to local log files.
  - **No cron/scheduler is guaranteed** on the free tier. Features must not depend on scheduled jobs
    (e.g. "overdue" is computed at query time). Queued jobs need a worker we don't have yet: work that
    must happen goes in the request or `defer()`.
  - **The database connection uses SSL** (`sslmode=require`) in production.
- The image takes all configuration from env vars (no `.env` inside). Config/route/view/event caches are
  built at container start (`docker/entrypoint.sh`), so never call `env()` outside `config/` files.

## Database (PostgreSQL)

- JSON columns are `jsonb`.
- No DB-level enums: "enum" columns are `string` and the allowed values are enforced by PHP backed-enum
  casts (`App\Enums\*`, which throw on unknown values) plus `Rule::enum()` in Form Requests. Adding a value
  needs no migration.
- Case-insensitive uniqueness uses expression indexes on `lower(column)` (users.email, tags/statuses/sources
  name). Emails are also stored lowercased + trimmed by the User model.
- PostgreSQL does **not** index foreign keys automatically: add `->index()` to every new FK column unless
  it leads another index.
- Migrations must run on both PostgreSQL and SQLite. Raw `DB::statement()` only for static DDL that both
  support (like expression indexes), never with interpolated values.

## Conventions
- Models use explicit `$fillable` (never `$guarded = []`). Ownership fields such as `created_by`,
  `user_id` and `lead_id` are set in code, not mass-assigned from requests.
- Roles: `App\Enums\Role` (admin, manager, sales). Use `$user->isAdmin()`, `isManager()`, `isSales()`.
- Gates (in `AppServiceProvider`): `manage-users`, `manage-settings`, `view-audit-log` (admin);
  `view-users`, `export-leads`, `import-leads` (admin, manager).
- CSP is strict (nonce-based, no `unsafe-inline`/`unsafe-eval`). Do not add inline `<script>`/`<style>`
  or `style=""` attributes. Put Alpine logic in `resources/js/app.js` via `Alpine.data()` and
  reference components by name in `x-data`. Do not load assets from CDNs.
- "Overdue" follow-ups are computed (`pending` and `due_at < now()`), never stored.
- `leads.original_source_id` and `leads.reference` never change after creation (enforced in the model).
- Uploaded import files must be deleted after processing. The local disk is not served over HTTP.
- No user is ever seeded with a known password. First admin: `php artisan crm:create-admin`.
- Every new route lives inside the `auth` group in `routes/web.php` unless it is an auth screen.
  `AccessControlTest` fails if a non-public route lacks auth.
- Tests: add feature tests for every new permission rule. Tests must not make network calls.

## UI rules

**The prototype is the source of truth for the look and behaviour.** It lives in `design/` (clone the
design repo there; gitignored) with screenshots in `docs/design-screens/` and an inventory in
`docs/DESIGN_AUDIT.md`. Match it exactly: colours, font, sizes, spacing, radius, shadows, layout,
labels and their case, how many buttons a screen has, tabs, modals, slide-overs and interactions.
When building a screen, open its TSX in `design/src/components/...` and port the Tailwind class strings.
The prototype's data, auth and permission logic is fake: never copy it (audit §7).

### Design tokens
- Defined in the `@theme` block of `resources/css/app.css` (from audit §2): Tailwind's default palette,
  accent indigo-600 (hover indigo-700), slate neutrals, white cards on a slate-50 canvas, rounded-lg /
  xl / 2xl, `shadow-2xs` cards, 12px (`text-xs`) base UI text, `text-2xs` (11px), `text-3xs` (10px) and
  `text-4xs` (9px) meta text, sidebar `w-60`, header `h-16`.
- Font: Plus Jakarta Sans 400/500/600/700, self-hosted via `@fontsource` (no CDN).
- Icons: Lucide (same set as the prototype), rendered server-side as inline SVG:
  `<x-lucide-phone class="size-3.5" aria-hidden="true" />`. Names match the prototype's lucide-react imports.
- Status and tag colours: only the prototype palette in `config/crm.php` (`palette`); `<x-badge>` maps the
  stored hex back to the prototype's classes.

### Rules we keep on top of the prototype (they protect users or security)
- **Contrast (WCAG AA, including small text):** text is never lighter than `slate-500`. The prototype's
  `text-slate-400` text becomes `text-slate-500`; `slate-400` is for icons, borders and placeholders of
  disabled controls only.
- **Visible keyboard focus** on everything interactive (global `:focus-visible` outline), a `<label>`
  for every input (visually hidden where the prototype shows none).
- **Errors in plain language**, shown under the field (customise Form Request `messages()` /
  `attributes()`), styled like the prototype. Never show technical errors, codes or stack traces.
- **Destructive actions** (archive, delete tag, deactivate user) always use `<x-confirm-dialog>`, whose
  title names the item. The prototype has none; ours look like its modals.
- **Submit buttons** show loading text and are disabled while submitting (built into `<x-button>`).
- **Empty, loading and error states** on every list and screen, designed in the prototype's style
  (`<x-empty-state>`, `<x-flash>`), since the prototype mostly lacks them.
- **Feedback** after every action ("Lead saved", "3 leads assigned to Priya") via `<x-flash>`.
- **Glossary** below: use exactly those words (the prototype says "Rep", "Assignee" and "ARR"; we say
  "Assigned to" and "Deal value").
- **Never format dates or money directly:** use `<x-money>` / `App\Support\Money` and the date helper.
- Lists paginate at 25 rows. Under our CSP, all Alpine logic goes in `resources/js/app.js`
  (`Alpine.data`); no inline scripts or `style=""`.
- Laptop and tablet widths must work well; phones usable.

### Components: use only these
All UI is built from the components in `resources/views/components`. Do not create one-off styles on
pages that duplicate a component; if something is missing, extend a component (or add one and list it here).

| Component | Use (prototype counterpart) |
|---|---|
| `<x-layouts.app title="…" [subtitle] [count] [width]>` | Page shell: sidebar, header (search, alerts bell, user menu), flash, page header from `title`/`subtitle`/`count` + `actions`/`more` slots. `width`: `5xl` / `6xl` / `7xl` (default). |
| `<x-layouts.guest>` | Sign-in screens (AuthView card). |
| `<x-page-header>` | Bold title + count chip + subtitle; actions top-right. Rendered by the layout. |
| `<x-button>` | `variant`: primary / secondary / danger / danger-soft / success / ghost / text / dark; `size`: sm / md / lg; `icon="plus"`; `href` renders a link; `loading="Saving…"`. |
| `<x-input>`, `<x-select>`, `<x-textarea>` | Label above ("First Name *"), `hint`, error under the field, `bag`; `size="lg"` + `icon` + `reveal` for sign-in screens; `<x-slot:aside>` next to the label. |
| `<x-more-details>` | Collapsible section for less-used form fields. Pass `:open` when one of its fields has an error. |
| `<x-badge>` | `color="#hex"` status/tag badge with dot; `tone` (neutral/red/amber/green/blue/indigo) bold chip, e.g. Overdue. |
| `<x-table :paginator>` | Table card with footer ("Showing 1–25 of 240") and page links. `<tr data-href>` rows are clickable (also put a real link in the first cell). |
| `<x-empty-state title icon [tone=success]>` | Every list's empty state, with the next action in `actions`. |
| `<x-flash>` | Success (fades) / error (stays) banner. Controllers: `->with('status', …)` / `->with('error', …)`. |
| `<x-confirm-dialog>` | Every destructive action; title names the item. |
| `<x-modal name title [subtitle] [size] [:open]>` | Prototype modals. Open with `x-data="modalTrigger" data-modal="name" x-on:click="open"`. `footer` slot. |
| `<x-slide-over :close-href label>` | Right-hand panel with its own URL (lead detail). `header` slot. |
| `<x-tabs :tabs [active] variant>` + `<x-tab-panel name>` | `underline` (lead detail), `pills` (settings, quick filters), `segmented` (Table/Pipeline). Items with `href` are link tabs. |
| `<x-dropdown>` | Menus (bulk actions, user menu). `trigger` slot; `align`, `width`, `up`. |
| `<x-more-menu>` | Secondary actions in the page header (layout's `more` slot). |
| `<x-stat label [tone] [hint] [href]>` | Stat card (dashboard, reports, import validation). |
| `<x-money>` | Every money amount: `:amount` (minor units) + `:currency`, or `:totals` ("$12,400 · ₹3,50,000"). |

## Glossary: use exactly these words everywhere

| Term | Meaning / usage |
|---|---|
| **Monks CRM** | The product name. Always rendered via `config('app.name')`. Page titles: "Leads · Monks CRM". |
| **Lead** | A potential client (person and/or company). Never "contact", "prospect" or "record". |
| **Status** | Where a lead is in the pipeline (New, Contacted, Won, …). |
| **Source** | Where a lead came from (Website, Referral, …). "Original source" never changes. |
| **Priority** | Low, Medium, High, Urgent. |
| **Deal value** | The expected value of a lead, in that lead's currency. Never "ARR", "revenue" or "amount" in the UI. |
| **Assigned to** | The user responsible for a lead or follow-up. Never "owner" or "rep". |
| **Follow-up** | A scheduled next step with a due date (plural "Follow-ups"). **Overdue** = still pending and past its due date. |
| **Activity** | Something that happened on a lead (call, email, meeting, …), shown on the timeline. |
| **Note** | Free text written on a lead. |
| **Archive** | Hide a lead from lists (soft delete). Never "delete" for leads. |
| **Import / Export** | Bringing leads in from a CSV/Excel file / downloading leads as a file. |
| **Two-step sign-in** | User-facing name for two-factor authentication (2FA). |
| **Admin / Manager / Sales** | The three roles. |
