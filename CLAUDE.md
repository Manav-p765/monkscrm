# Monks CRM: project rules

**Monks CRM** is the internal lead-management CRM for Hybrid Monks LLP, deployed at https://crm.hybridmonks.com.
The product name lives in exactly one place, `APP_NAME` → `config('app.name')`. Never hard-code
"Monks CRM" in views, emails or code; always use `config('app.name')`.
It holds client lead data. **Security is the top priority:** no data may be reachable
without authentication and correct authorization. Keep code clean and practical; do not
over-engineer and do not add packages unless clearly needed.

## Stack (fixed)
- Laravel 13, PHP 8.3+, MySQL 8 (SQLite in-memory for tests only)
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

Users are sales and operations staff, not technical users. Someone with zero training should
understand every screen within a few seconds. **When in doubt, remove rather than add.**

### Components: use only these
All UI is built from the components in `resources/views/components`. Do not create one-off
styles on individual pages; if something is missing, extend a component (or add one and list it here).

| Component | Use |
|---|---|
| `<x-layouts.app title="…" [wide]>` | Page shell. Renders the page header from `title` plus the `actions` / `more` slots, and the flash message. Use `wide` for full-width tables. |
| `<x-page-header>` | Title top-left, max one primary action top-right, "More" menu. Rendered by the layout. |
| `<x-button>` | `variant`: primary / secondary / danger / text. `href` renders a link. `loading="Saving…"` text while submitting. |
| `<x-input>`, `<x-select>`, `<x-textarea>` | Label above, `required` marker, `hint`, error shown next to the field. `bag` for named error bags. |
| `<x-more-details>` | The single "More details" section for less-used form fields. Pass `:open` when one of its fields has an error. |
| `<x-badge>` | `color="#hex"` for status colors from the database; `tone` (neutral/red/amber/green/blue) for fixed meanings, e.g. `:tone="$lead->priority->tone()"`. |
| `<x-table :paginator>` | Lists. `<x-slot:head>` for the header row; `<tr data-href="…">` makes a row clickable (also put a real link in the first cell). |
| `<x-empty-state title="…">` | Every list: says what to do next, with the relevant button in `actions`. |
| `<x-flash>` | Rendered by layouts. Controllers use `->with('status', 'Lead saved')` (fades out) or `->with('error', '…')` (stays). |
| `<x-confirm-dialog>` | Required for every destructive action. The title names the item. |
| `<x-more-menu>` | Secondary actions in the page header (via the layout's `more` slot). |

### Layout
- One sidebar + one main content area. No nested menus, no tabs inside tabs, no dropdown navigation.
- Every page: a clear title top-left and **at most one primary button** top-right (e.g. "Add lead").
  Secondary actions are text buttons or go in the single "More" menu.
- Content is left-aligned with a comfortable reading width (`max-w-4xl`); tables may use full width (`wide`).
- Use spacing and type hierarchy, not boxes, borders and cards everywhere.

### Visual style
- Light theme only. White / very light grey backgrounds; one accent color (blue-700) for primary
  buttons, links and the active nav item; neutral greys for everything else.
- Color is for meaning only: status badges (status color from the database), priority (only High =
  amber and Urgent = red; Low/Medium grey), overdue follow-ups red, success green. No decorative
  colors, gradients or shadows.
- One font: the system UI stack. Base size 15px (`text-sm`), 16px for emphasis (`text-base`).
  Sentence case everywhere; no all-caps labels.
- Icons only where they speed up recognition (phone, email, calendar), always with a text label in buttons.

### Forms
- Labels above fields, in plain language ("Phone number", never "phone_normalized").
- Show only the most-used fields; put the rest in `<x-more-details>`.
- Mark required fields. Errors appear next to the field in plain language saying how to fix it
  (customise Form Request `messages()` / `attributes()` where Laravel's default wording is technical).
- Buttons say exactly what they do: "Save lead", "Schedule follow-up", "Import 240 leads".
  Never "Submit" or "OK".
- Destructive actions (archive, delete, deactivate user) always use `<x-confirm-dialog>` naming the item.
- Submit buttons show a loading text and are disabled while submitting (built into `<x-button>`).

### Tables and lists
- Only the columns people need to scan. Clicking a row opens the item.
- Search box always visible at the top of every list. Filters sit in one simple row/panel with a
  single "Clear filters" link.
- Every list has an `<x-empty-state>` ("No leads yet. Add your first lead or import a CSV.") with the button.
- Paginate at **25** rows per page.

### Feedback
- After every action, a short success message: "Lead saved", "3 leads assigned to Priya".
- Errors say what went wrong and how to fix it. Never show technical errors, codes or stack traces.

### Accessibility and devices
- Visible keyboard focus on everything interactive, a `<label>` for every input, WCAG AA contrast.
- Must work well at laptop and tablet widths. Phones: usable, not optimised.
- Under our CSP, Alpine logic goes in `resources/js/app.js` (`Alpine.data`). No inline scripts or `style=""`.

## Glossary: use exactly these words everywhere

| Term | Meaning / usage |
|---|---|
| **Monks CRM** | The product name. Always rendered via `config('app.name')`. Page titles: "Leads · Monks CRM". |
| **Lead** | A potential client (person and/or company). Never "contact", "prospect" or "record". |
| **Status** | Where a lead is in the pipeline (New, Contacted, Won, …). |
| **Source** | Where a lead came from (Website, Referral, …). "Original source" never changes. |
| **Priority** | Low, Medium, High, Urgent. |
| **Assigned to** | The user responsible for a lead or follow-up. Never "owner" or "rep". |
| **Follow-up** | A scheduled next step with a due date (plural "Follow-ups"). **Overdue** = still pending and past its due date. |
| **Activity** | Something that happened on a lead (call, email, meeting, …), shown on the timeline. |
| **Note** | Free text written on a lead. |
| **Archive** | Hide a lead from lists (soft delete). Never "delete" for leads. |
| **Import / Export** | Bringing leads in from a CSV/Excel file / downloading leads as a file. |
| **Two-step sign-in** | User-facing name for two-factor authentication (2FA). |
| **Admin / Manager / Sales** | The three roles. |
