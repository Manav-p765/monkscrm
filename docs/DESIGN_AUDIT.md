# Design audit: Google AI Studio prototype (`design/`)

Date: 2026-10-07. Analysis only: no application code was changed.
Source: `design/` (clone of `github.com/hiteshhmalviyaa-bit/CRM-`, 2 commits, nested `.git`).
Screenshots: [`docs/design-screens/`](design-screens/) (43 PNGs, 1440×900, see §9).

> **PRD note:** the PRD document itself is not in this repo. The PRD mapping below relies on the
> "PRD Section N" references in the prototype's code comments and on `docs/PROGRESS.md`. If the PRD
> says otherwise, the PRD wins.

## Scope update (2026-10-07)

**Every feature in the prototype is in the MVP**, including the Kanban board (step 3), the alerts bell
(step 4), Reports (step 5), teams, bulk tagging, the permissions table and reopening a completed
follow-up. The step plan is in `docs/PROGRESS.md` "MVP plan". Where §5 below says "Not in our plan"
or "Phase 2" for one of these, this update overrides it.

### Excluded from the MVP

| Feature | Reason |
|---|---|
| Self-signup as admin | Anyone on the internet could make themselves admin; the first admin comes from `php artisan crm:create-admin`. |
| One-click demo logins | They publish working credentials on the login page. |
| Role switcher | It lets any user act as any other user, bypassing every permission. |
| "Reset demo database" | One click would wipe real client data. |
| Tasks data type | The prototype has no screen for it, so there's nothing to build against. |

### Deal value and currency (replaces the single "currency" setting idea in G1 / D2)

- Per lead: `deal_value` (bigint, minor units: cents/paise) + `currency` (ISO 4217, `string(3)`, not
  null), because clients are both Indian and US.
- Settings: `enabled_currencies` (default `["USD","INR"]`, chosen from the fixed list USD, INR, EUR,
  GBP, AUD, CAD) and `default_currency` (default `USD`, must be enabled). A disabled currency stays
  valid and displayed on existing leads.
- One formatter (`App\Support\Money`, `<x-money>`): INR `₹1,00,000.00` (Indian grouping), USD
  `$100,000.00`, others with their own symbol and standard grouping; decimals hidden when zero.
- Amounts in different currencies are **never added together and never converted**. Every total the
  prototype shows (dashboard cards, Kanban column totals, Reports) becomes one entry per currency,
  e.g. "$12,400 · ₹3,50,000".
- Leads list gets a currency filter; import can map a currency column (fallback `default_currency`);
  Form Requests validate against the enabled list; currency settings changes are audit-logged.

---

## 0. Safety and housekeeping

| Item | Finding |
|---|---|
| Nested git repo | **Yes.** `design/.git` exists (its own history: `e195804 Initial commit`, `1769624 feat: initialize lead management CRM project`). Not deleted. Our repo sees `design/` as an untracked embedded repo, so `git add .` would record only a gitlink pointer. |
| Real secrets | **None found.** No `.env` or `.env.local`, no API keys or tokens in the working tree or in the prototype's git history (history only has `.env.example`). |
| Placeholder secrets | `design/.env.example:4`: `GEMINI_API_KEY` placeholder (not a real key). `design/.env.example:9`: `APP_URL` placeholder. `design/metadata.json:5` declares `MAJOR_CAPABILITY_SERVER_SIDE_GEMINI_API`. `@google/genai` is a dependency but **no source file imports it**. |
| Hard-coded demo credentials | Password `password123`: `design/src/data/seedData.ts:16,29,42,55,68`; `design/src/context/CRMContext.tsx:154,326,330,334,369`; `design/src/components/auth/AuthView.tsx:76,77,308,313,379`. Password `Welcome123!`: `CRMContext.tsx:414`, `components/users/UsersTeamsView.tsx:43,92,265`. These are fake but must never reach our app. |
| Customer data | Seed leads (`seedData.ts`) use fictional names and companies, but real-looking domains (e.g. `sterlinglogistics.com`) and **phone numbers outside the 555 range** (e.g. 415/617/312/514 area codes) that could belong to real people. Treat as fake. Never import them into our database or demo seeder. |
| Ignore rules (committed `bd2acd5 Design audit: ignore rules`) | `.gitignore`: `/design/.env`, `/design/.env.*` (except `.env.example`), `/design/node_modules`, `/design/dist`. `.dockerignore`: `design`. |
| Local state left behind | `design/node_modules/` (installed with `--ignore-scripts` for the screenshots, plus `puppeteer-core` with `--no-save`). Gitignored and dockerignored. Safe to delete. |

---

## 1. Tech stack of the prototype

| Area | What it uses | Where |
|---|---|---|
| Framework | React 19 SPA (`react`, `react-dom` ^19.0.1), TypeScript (^7), Vite 8 (`@vitejs/plugin-react`) | `package.json`, `src/main.tsx` |
| Styling | Tailwind CSS 4 via `@tailwindcss/vite`, utility classes inline in JSX. Default Tailwind palette, no `@theme` overrides. One custom rule (scrollbar). | `src/index.css` |
| UI libraries | None. Every component is hand-written. `motion` is installed but **never imported**. `animate-in`/`fade-in`/`zoom-in-95` classes are used (17×) but no plugin defines them, so they do nothing. | |
| Icons | `lucide-react` ^0.546 | all components |
| Fonts | **Plus Jakarta Sans** 400/500/600/700 and JetBrains Mono 400/500 from **Google Fonts CDN** (`index.html:14-16`). JetBrains Mono is loaded but never wired to `font-mono`. | `index.html`, `src/index.css:5` |
| Charts | None. Bars are `div`s with inline `style={{width: pct%}}` (`ReportsView.tsx:158`). | |
| Routing | None. `useState<ActiveNavTab>` in `App.tsx` switches views; no URLs, so no deep links and the back button doesn't work. Lead detail, forms and profile are overlay modals. | `src/App.tsx` |
| State / data | Single React context (`CRMContext.tsx`, 1160 lines) holding **everything**: users (with plaintext passwords), leads, notes, activities, follow-ups, statuses, sources, tags, settings, import history. Seeded from `src/data/seedData.ts` and **persisted to `localStorage`** (9 keys, `crm_*_v1`). | `src/context/CRMContext.tsx` |
| API | None. No `fetch`. `express`, `dotenv`, `@google/genai` are dependencies but unused. **No Gemini calls.** | |
| File parsing | `xlsx` 0.18.5 (SheetJS, npm build, which has known CVEs) parses CSV/XLSX **in the browser** | `components/imports/BulkImportView.tsx` |
| Avatars | Remote Unsplash image URLs | `seedData.ts`, `CRMContext.tsx:375,423` |

---

## 2. Design tokens

All values are Tailwind 4 defaults (Tailwind 4 defines them in OKLCH; the hex values below are the
standard sRGB equivalents). The only literal hex values in the source are the scrollbar colors.

### Colors

| Role | Tailwind | Hex | Used in |
|---|---|---|---|
| **Accent / primary** | indigo-600 | `#4f46e5` | primary buttons, active nav icon, logo tile, focus border, links |
| Accent hover | indigo-700 | `#4338ca` | button hover, active nav text |
| Accent tint | indigo-50 / indigo-100 / indigo-200 | `#eef2ff` / `#e0e7ff` / `#c7d2fe` | active nav bg, selected row, chip borders |
| App background | slate-50 | `#f8fafc` | `body`, `main` (`index.html:18`, `App.tsx:75`) |
| Surface | white | `#ffffff` | sidebar, header, cards, modals |
| Muted surface | slate-100 | `#f1f5f9` | segmented controls, kanban columns, badges |
| Border | slate-200 | `#e2e8f0` | cards, inputs, dividers (`slate-100` `#f1f5f9` for inner dividers) |
| Input border (auth) | slate-300 | `#cbd5e1` | `AuthView.tsx` |
| Text strong | slate-900 | `#0f172a` | titles, names |
| Text body | slate-700 / slate-600 | `#334155` / `#475569` | body, labels |
| Text muted | slate-500 / slate-400 | `#64748b` / `#94a3b8` | hints, meta, icons |
| Dark bar | slate-900 / slate-800 / slate-700 | `#0f172a` / `#1e293b` / `#334155` | active quick-filter pill, bulk-action bar |
| Success | emerald-50/200/600/700/800 | `#ecfdf5` `#a7f3d0` `#059669` `#047857` `#065f46` | Won, "Done", import success, revenue figures |
| Danger / overdue | rose-50/200/500/600/700 | `#fff1f2` `#fecdd3` `#f43f5e` `#e11d48` `#be123c` | Overdue, Urgent, delete, Admin role chip |
| Warning / due today | amber-50/100/200/500/700/800 | `#fffbeb` `#fef3c7` `#fde68a` `#f59e0b` `#b45309` `#92400e` | Due today, High priority, duplicate warning |
| Scrollbar | literal | `#cbd5e1`, hover `#94a3b8` | `src/index.css:18,22` |

**Status colors** are stored as Tailwind color *names* (`blue`, `amber`, `indigo`, `purple`, `teal`,
`cyan`, `emerald`, `rose`, `slate`, `zinc`) and mapped to `bg-*-50 / text-*-700 / dot *-500 /
border-*-200` classes in `src/utils/crmHelpers.ts:89-114` (`getStatusBadgeClasses`). `cyan` isn't
mapped and falls back to slate. We store hex (`lead_statuses.color`), so we'd render the
same badge look from hex (tinted background + dot).

**Priority** (`crmHelpers.ts:116-127`): Urgent = rose-600 dot + rose-700 semibold; High = amber-500 dot
+ amber-700; Medium = slate-400 / slate-600; Low = slate-300 / slate-500. This already matches our
`LeadPriority::tone()` rule (only High and Urgent colored).

### Typography (from `src/index.css:5`, `index.html:16`)

- Family: `'Plus Jakarta Sans', system-ui, …` (Google Fonts). Mono: Tailwind default stack.
- Sizes as used (count of occurrences): `text-xs` 12px (347, **the base UI size**), `text-[11px]`
  (79), `text-[10px]` (72), `text-sm` 14px (26), `text-2xl` 24px (16, stat numbers), `text-xl` 20px
  (8, page titles), `text-[9px]` (6), `text-base` 16px (3, modal titles), `text-lg` (1).
- Weights: 400 / 500 / 600 / 700. Section labels use `uppercase tracking-wider font-bold text-[10px]`.

### Spacing, radius, shadow, layout

- Spacing: Tailwind 4px scale. Page padding `p-8` (32px); card padding `p-5`/`p-6`; table cells
  `py-3.5 px-4`; gaps `gap-2`…`gap-6`; vertical rhythm `space-y-6`/`space-y-7`.
- Radius: `rounded-lg` 8px (149, buttons/inputs), `rounded-xl` 12px (58, cards/panels),
  `rounded-2xl` 16px (34, big cards/modals), `rounded-md` 6px, `rounded` 4px (badges), `rounded-full`.
- Shadows: `shadow-2xs` (59, almost every card/button), `shadow-xs`, `shadow-sm`, `shadow-md`,
  `shadow-lg` (bulk bar), `shadow-xl` (dropdowns), `shadow-2xl` (modals). Modal backdrop
  `bg-black/50 backdrop-blur-xs` or `bg-slate-900/50`.
- Layout: sidebar `w-60` (240px) fixed; top header `h-16` (64px) sticky; content max widths
  `max-w-6xl` (dashboard), `max-w-7xl` (lists, reports, users, import), `max-w-5xl` (settings);
  lead detail = right slide-over `max-w-3xl`; forms = centered modal `max-w-3xl`/`max-w-md`.
- Breakpoints: Tailwind defaults; only `sm` (640px) and `lg` (1024px) are used. The sidebar never
  collapses, so phone widths are not handled.

### Conflicts with our CLAUDE.md UI rules (settled by D1: copy the prototype)

| Prototype | Our rule |
|---|---|
| Accent indigo-600 `#4f46e5` | One accent, blue-700 `#1d4ed8` |
| Plus Jakarta Sans from Google Fonts CDN | System UI font stack; no CDN assets (CSP) |
| 12px base text, 10–11px meta, 9px chips | 15px base (`text-sm`), 16px emphasis |
| `uppercase tracking-wider` labels everywhere | Sentence case, no all-caps labels |
| Bordered + shadowed cards on every section, `active:scale` press effects | "Spacing and type, not boxes, borders and cards"; no shadows |
| 2–3 primary buttons per screen (sidebar "New Lead", header "Add Lead", page "Add Lead") | At most one primary button per page |
| Tabs inside the lead drawer; tab bar inside Settings | No tabs inside tabs |
| Colored revenue numbers (emerald) and decorative icon colors | Color for meaning only |
| Remote avatar photos | Not allowed by CSP `img-src`; we'd use initials |

---

## 3. Screen inventory

Roles: prototype `admin` / `manager` / `standard` = our Admin / Manager / Sales.
Screenshot numbers refer to `docs/design-screens/NN-*.png`.

### 3.1 Sign in / Create admin account: `components/auth/AuthView.tsx` (01, 02, 03)
- **Purpose:** gate the app. Two tabs: "Sign In" and "Create Admin Account".
- **Fields:** Sign in: email, password (show/hide eye). Create admin: full name, company name,
  work email, master password.
- **Actions:** Sign In to CRM; Forgot password? (opens modal saying the admin resets passwords and
  showing the default password); Create Admin Account & Launch; **1-click test logins** (first 3 users,
  password printed on screen).
- **Errors:** red banner above the form (unknown user, deactivated, wrong password, which also reveals
  the default password).
- **Roles:** everyone (unauthenticated).

### 3.2 App shell: `components/layout/Sidebar.tsx`, `components/layout/Navbar.tsx` (04, 05, 06)
- **Sidebar:** logo "CRM / Lead CRM / Internal Management"; primary **New Lead** button; "Workspace"
  nav: Dashboard, Leads (count badge), Tasks & Follow-Ups (due-count badge, amber), Bulk Import,
  Reports & Pipeline; "Admin & Org": Team & Access (admin) / Team Directory (manager), Settings
  (admin). **Active role switcher** (`<select>` of all users). Current user card (opens My Profile),
  Log Out.
- **Header:** global search ("Press '/'"), dropdown of up to 8 matching leads (name, reference, status
  badge, company, email, phone, value); **bell** with red pulse dot, dropdown "Follow-Up Alerts"
  (overdue / due today, click opens lead); "Logged in as" avatar + name + role chip (opens profile);
  **Add Lead** primary; Logout.
- **Keyboard:** `/` focuses search; `Esc` closes search and alerts dropdowns.
- **Roles:** all; admin section depends on role.

### 3.3 Dashboard: `components/dashboard/DashboardView.tsx` (04, 41)
- **Header:** "Good day, {first name}" + role-specific subtitle; "View All Leads" (secondary).
- **4 stat cards (clickable):** Total Leads → lead list; New Leads → list filtered New; Follow-Ups Due
  (today + overdue) → follow-ups page; Closed Won ARR ($) → list filtered Won.
- **Pipeline strip:** 6 fixed stages (New, Contacted, Follow-Up, Qualified, Opportunity, Won), each with
  count + $ value, click → filtered list; "Active Value" total.
- **Follow-up queue:** next 5 pending (sorted by due), lead name (opens lead), company, notes, Overdue /
  Due today / date, type, assignee, **Done** button. "View All".
- **Recent team activity:** latest 6 activities across visible leads (initial avatar, user, text, lead
  link, relative time).
- **Empty states:** "No pending follow-ups right now." / "No recent activity logged yet."
- **Roles:** all (data limited to visible leads).

### 3.4 Lead list (table): `components/leads/LeadListView.tsx` (07–10, 12, 42)
- **Header:** "Lead Directory" + count chip; Table / Pipeline toggle; **Export CSV** (admin, manager);
  **Add Lead** primary.
- **Quick filter pills:** All Leads, My Assigned Leads, Follow-Up Due / Overdue, Unassigned Leads,
  Urgent & High Priority.
- **Filter panel:** search (name, company, email, phone, reference, city, state) with clear ×; Stage
  select; Rep select (incl. Unassigned); **More** toggles Source, Priority, Tag selects; **Reset** link.
- **Columns:** checkbox; Lead & Organization (name, reference, company · job title); Status (badge
  with dot); Priority (dot + label); Contact (mailto email, tel phone); Assigned Rep (avatar + name /
  "Unassigned"); Next Follow-Up (date, red Overdue / amber Due today); Est. Value ($); Source.
  Sortable: name, status, priority, next follow-up, value (default created desc).
- **Row click** opens the lead detail drawer. Footer: "Showing X of Y leads". **No pagination.**
- **Bulk bar** (dark, after selecting ≥1): N selected; Change Status ▾; Assign Rep ▾ (incl.
  Unassign); Add Tag ▾; Export (N) (admin, manager); **Delete** (admin, no confirmation); Cancel.
- **Empty state:** "No leads match your criteria" + "Reset all filters". There's no empty state for
  "no leads at all".
- **Roles:** all; Export for admin/manager; Delete for admin. Bulk status/assign/tag shown to everyone.

### 3.5 Lead pipeline (Kanban): `components/leads/KanbanBoard.tsx` (11)
- Columns = active statuses in order (10). Header: color dot, name, count, total $.
- **Card:** drag handle, name, reference, company · title, $ value, priority, up to 2 tags (+N),
  assignee first name / Unassigned, next follow-up date (red/amber), ‹ › step buttons
  ("Drag or Step").
- **Actions:** drag and drop between columns (changes status, logs activity, green "Moved X to stage Y"
  banner for 3s); step back / forward buttons; "+ Add Lead" per column (opens the create modal and
  doesn't pre-set the status); click card → detail.
- **Empty column:** dashed "No leads in stage · Drag leads here to move".
- **Roles:** all.

### 3.6 Create / edit lead: `components/leads/LeadFormModal.tsx` (19, 20, 21)
- **Modal** "Create New Lead" / "Edit Lead: {name}".
- **Sections & fields:** 1 Basic: first name*, last name, company*, job title. 2 Contact: primary
  email*, primary phone* (rule: name **or** company, and email **or** phone), secondary email,
  secondary phone, website. 3 CRM: status*, source*, priority, assigned representative (default =
  me), estimated deal value ($), tags (toggle chips). 4 Address: street, city, state, postal code,
  country (default "United States"; there's no address line 2 input although the field exists).
  5 Description; initial internal note (create only).
- **Live duplicate detection** while typing email/phone: amber banner "Potential Duplicate Record
  Found … View Existing Lead · Proceed Anyway".
- **Errors:** single red banner at top (not next to fields).
- **Actions:** Cancel, Create Lead / Save Changes.
- **Roles:** all.

### 3.7 Lead detail drawer: `components/leads/LeadDetailModal.tsx` (13–18)
- **Right slide-over.** Header: name, reference, **priority select** (inline change), company · title ·
  $ value; Edit (pencil), Delete (admin, no confirmation), Close.
- **Inline selects:** Stage, Assignee (any user, any role can change).
- **Quick actions:** Log Call, Log Email, Log Meeting, Schedule Follow-Up.
- **Tabs:** Activity Timeline (count) · Internal Notes (count) · Follow-Ups & Tasks (count) ·
  Contact Details.
  - Timeline: vertical line, icon per type, user, datetime, text, optional "Outcome". "+ Log
    Interaction".
  - Notes: textarea "Add Timestamped Internal Note" + Post Note; list (author, datetime, text).
    Empty: "No notes recorded yet…".
  - Follow-ups: list (type, Completed/Overdue/Due today badge, notes, due date+time, assignee, Mark
    Done); "+ Schedule Action". Empty: "No follow-ups scheduled for this lead."
  - Contact details: emails (mailto), phones (tel), website (external link), address, source,
    created, last contacted, tags.
- **Sub-modal "Log Interaction with {name}":** type (Phone Call, Email, Video Meeting / Demo, SMS
  Text, Internal Review Note, Other), notes/description*, outcome. Save Activity.
- **Sub-modal "Schedule Follow-Up":** action type (Call, Send Email, Product Demo, Check-in, Send
  Proposal; note there's no "meeting"), assigned to, due date*, due time, instructions. Set
  Follow-Up.
- **Roles:** all; delete admin only.

### 3.8 Tasks & follow-ups: `components/tasks/TasksFollowUpsView.tsx` (22, 23, 24)
- **Header:** "Tasks & Follow-Ups Queue" + count; **Schedule Follow-Up** primary.
- **Pills:** All Pending, Overdue (n), Due Today (n), Upcoming, Completed History.
- **Row:** completion checkbox (toggles pending ↔ completed), type chip, lead name (opens lead),
  company, notes (struck through when done), "Rep: name", due date+time, Overdue / Due today chip;
  Done; View Lead.
- **Empty:** green check "No follow-ups in this queue · You are all caught up…".
- **Modal "Schedule Lead Follow-Up":** lead select (all visible leads), type, assigned rep, due
  date*, due time, notes*.
- **Roles:** all (visible leads only).

### 3.9 Bulk import: `components/imports/BulkImportView.tsx` (25–31)
- **Header:** "Bulk Lead Import · CSV & Excel"; toggle Import Wizard / Past Imports (count); Sample
  Template (download CSV).
- **Stepper (6):** Upload File → Read File → Map Fields → Validate → Duplicates → Preview & Import.
  1. Dropzone (click; there's no real drag-and-drop handler) for .csv/.xlsx/.xls, "up to 5,000 rows";
     "Load Pre-Built Sample Lead List".
  2. File name, rows × columns, first 3 rows preview; "Choose different file".
  3. Per column: header, sample value, CRM target select (auto-guessed; 16 fields + Do Not Import).
  4. Cards: Total, Ready & Valid, Potential Duplicates, Invalid / Malformed; first 5 row errors.
  5. Radio cards: Skip duplicates (recommended) / Update existing / Import all as new.
  6. Summary + preview of 8 rows (Valid / Duplicate / Invalid); "Confirm & Execute Import Now" →
     success panel (new / updated / skipped) with "Start Another Import" / "View Import History Log".
- **Past imports table:** file name, date, imported by, strategy, total, imported, updated, skipped.
- **Errors:** `alert()` for unreadable files.
- **Roles:** shown to **everyone** in the prototype (its own permission matrix says admin + manager).

### 3.10 Reports & pipeline: `components/reports/ReportsView.tsx` (32)
- "Executive & Team Reports · Live Pipeline".
- Cards: Closed Won Revenue, Active Pipeline, Win Rate (won ÷ won+lost), Average Deal Size.
- "Pipeline Velocity & Funnel Health": bar per status (count, $, %).
- "Sales Representative Scorecard": rep (avatar, email), team, assigned, contacted, won, won $,
  active $, follow-up compliance % (≥80 green, else amber).
- **Roles:** shown to everyone and computed over **all leads**, ignoring visibility.

### 3.11 Users & teams: `components/users/UsersTeamsView.tsx` (33, 34, 35, 43)
- **Header:** "Users & Teams Management · RBAC Directory"; **Invite Team Member (Set Password)** (admin).
- **Table:** member (avatar, name, title), role chip, team, email + phone, Active / Deactivated, last
  active; actions (admin): reset password (key), edit role & team, deactivate / reactivate (not self).
- **Role & access control matrix** (static table, 11 permissions × 3 roles).
- **Modals:** Invite (name*, email*, initial password* + "Generate Strong Password", role, team
  select, job title, phone) → **credentials card** (email + plaintext password, copy buttons, "Copy
  Full Invitation Message"); Reset password (type a new password) → credentials card; Edit member
  (role, team, title).
- **Roles:** admin (full), manager ("Team Directory", read-only).

### 3.12 Settings: `components/settings/SettingsView.tsx` (36–39)
- "CRM Configuration & Settings · Admin Only". Sub-tabs:
  - **Lead statuses:** list (move up/down, badge, id, Won/Lost flag, description, Enabled/Disabled
    toggle, delete if >2); add form (label*, color name, description).
  - **Lead sources:** grid (name, id, Active/Disabled toggle); add form.
  - **Tags:** chips with delete; add form (the color select exists in state but isn't rendered).
  - **Security & duplicate rules:** checkbox "Allow Standard Users to view leads assigned to other team
    members"; read-only duplicate criteria (email primary+secondary; phone last 7 digits); **Reset Demo
    Database**.
- Non-admin fallback: "Restricted Administrator Area" (client-side only).
- **Roles:** admin.

### 3.13 My profile: `components/profile/MyProfileModal.tsx` (40)
- Modal: avatar, name, role chip; full name, email (read-only), direct phone, department (read-only),
  job title; "Profile details saved successfully!"; Log Out of Account; Close; Update Profile.
- There's no password change or 2FA (we already have both).
- **Roles:** all.

### 3.14 States summary
- **Loading states:** none (everything is synchronous in memory).
- **Error states:** login banner, lead form banner, import `alert()`, settings "Restricted" panel.
  No 403/404/500 pages.
- **Empty states:** listed per screen above. Missing: lead list with zero leads, import history empty,
  reports with no data.
- **Toasts:** only the Kanban "Moved…" banner and the profile "saved" message; most actions give no
  feedback.

---

## 4. Component inventory

The prototype has **no small reusable primitives**: every screen is one large component with
inline Tailwind. The reusable units and the repeated inline patterns are listed below, mapped to our
existing Blade components.

### 4.1 Actual components

| Component | File | Props | Used in |
|---|---|---|---|
| `App` / `CRMMainApp` | `src/App.tsx` | none | root; owns active tab, selected lead, modal flags |
| `CRMProvider` / `useCRM` | `src/context/CRMContext.tsx` | `children` | everything (global mock store) |
| `AuthView` | `components/auth/AuthView.tsx` | none | `App` when unauthenticated |
| `Sidebar` | `components/layout/Sidebar.tsx` | `activeTab`, `setActiveTab`, `onOpenNewLead`, `onOpenProfile` | `App` |
| `Navbar` | `components/layout/Navbar.tsx` | `onOpenNewLead`, `onSelectLead`, `onOpenProfile` | `App` |
| `DashboardView` | `components/dashboard/DashboardView.tsx` | `onSelectLead`, `onNavigateToLeads(status?)`, `onNavigateToTasks`, `onNavigateToImports` | `App` |
| `LeadListView` | `components/leads/LeadListView.tsx` | `onSelectLead`, `onOpenNewLead`, `initialStatusFilter?` | `App` |
| `KanbanBoard` | `components/leads/KanbanBoard.tsx` | `leads`, `onSelectLead`, `onOpenNewLead?` | `LeadListView` |
| `LeadFormModal` | `components/leads/LeadFormModal.tsx` | `initialLead?`, `onClose`, `onSelectExistingLead?` | `App` (create + edit) |
| `LeadDetailModal` | `components/leads/LeadDetailModal.tsx` | `leadId`, `onClose`, `onEditLead` | `App` |
| `TasksFollowUpsView` | `components/tasks/TasksFollowUpsView.tsx` | `onSelectLead` | `App` |
| `BulkImportView` | `components/imports/BulkImportView.tsx` | none | `App` |
| `ReportsView` | `components/reports/ReportsView.tsx` | none | `App` |
| `UsersTeamsView` | `components/users/UsersTeamsView.tsx` | none | `App` |
| `SettingsView` | `components/settings/SettingsView.tsx` | none | `App` |
| `MyProfileModal` | `components/profile/MyProfileModal.tsx` | `onClose` | `App` |

Helpers (`src/utils/crmHelpers.ts`): `formatCurrency` (USD, 0 decimals), `formatDate`
("Oct 1, 2026"), `formatDateTime` ("Oct 1, 4:20 PM"), `formatRelativeTime` ("Just now / 5m ago / 3h
ago / Yesterday / 3d ago / date"), `isOverdue`, `isDueToday`, `getStatusBadgeClasses`,
`getPriorityBadge`.

### 4.2 Repeated inline patterns → our Blade components

| Pattern (where) | Our component |
|---|---|
| Page title + subtitle + actions (every view) | `<x-layouts.app title>` / `<x-page-header>` |
| Indigo primary / white secondary / text / red buttons | `<x-button variant>` (restyle) |
| Label-above input/select/textarea (forms, modals) | `<x-input>`, `<x-select>`, `<x-textarea>` |
| Status badge with dot (list, search, kanban, settings) | `<x-badge color="#hex">` (add dot variant) |
| Priority dot + label | `<x-badge :tone>` (add dot style) |
| Overdue / Due today / Completed chips | `<x-badge tone="red/amber/green">` |
| Data table with header row, clickable rows, footer | `<x-table :paginator>` |
| Centered empty message (+ reset link) | `<x-empty-state>` |
| Centered modal shell (title, ×, body, footer buttons) | **missing**: new `<x-modal>` (native `<dialog>`, like `<x-confirm-dialog>`) |
| Right slide-over (lead detail) | **missing**: `<x-drawer>` if we keep the drawer (D3) |
| Stat card (dashboard, reports, import validation) | **missing**: `<x-stat>` |
| Segmented pill filters (leads, follow-ups) | **missing**: `<x-filter-pills>` (links with query string) |
| Dropdown menu (bulk actions, alerts) | `<x-more-menu>` (generalise) |
| Bulk action bar | **missing**: `<x-bulk-bar>` (Alpine) |
| Step progress (import) | **missing**: `<x-steps>` |
| "More filters" collapsible | `<x-more-details>` (or same pattern) |
| Inline success banner / toast | `<x-flash>` |
| Destructive confirm (prototype has none) | `<x-confirm-dialog>` |

---

## 5. Feature map

Steps refer to `docs/PROGRESS.md`: 1 Foundation · 2 Users/Settings/Audit · 3 Leads CRUD/list/search/
filters/duplicates · 4 Lead detail/notes/activities/follow-ups/timeline · 5 Dashboard · 6 Import/export ·
7 Hardening/2FA/deploy.

| Prototype feature | Our plan | Verdict |
|---|---|---|
| Sign in (email + password) | 1 (done, Fortify) | **In our MVP** (done) |
| Forgot password | 1 (done, real reset email) | **In our MVP** (done; prototype version is fake) |
| Public "Create Admin Account" sign-up | none (`crm:create-admin`) | **Not in our plan**: reject |
| 1-click demo logins, role switcher | none | **Not in our plan**: reject (fake/insecure) |
| Sidebar with nav count badges | 1 shell; counts in 3/4 | **In our MVP** |
| Global search box in header (`/` shortcut) | 3 | **In our MVP** (search page); live dropdown → D6 |
| Follow-up alerts bell (overdue + today) | 4/5 | **In our MVP** (server-rendered in layout) |
| Dashboard stat cards, pipeline strip, follow-up queue with Done, recent activity | 5 | **In our MVP** ($ figures depend on D2) |
| Role-specific dashboard subtitle/greeting | 5 | **In our MVP** |
| Lead table: columns, sort, row click, search, filters, quick filters, reset | 3 | **In our MVP** (+ pagination 25, which the prototype lacks) |
| Bulk change status / assign / unassign | 3 | **In our MVP** (assign: manager/admin only) |
| Bulk add tag | 3 | **In our MVP** (small) |
| Bulk delete | 3 as Archive | **In our MVP** as **Archive** with confirm (admin) |
| Kanban pipeline view with drag & drop + step buttons | not planned | **Not in our plan**: D4 |
| Export CSV (filtered / selected) | 6 | **In our MVP** (server-side, gated, audited) |
| Create/edit lead form (all fields, tags, initial note) | 3 | **In our MVP** |
| Duplicate detection on create (email/phone) | 3 | **In our MVP**; live-while-typing → D6 |
| "Proceed anyway" on duplicate | 3 (`allow_duplicate_override`, manager/admin) | **In our MVP** (sales can't override) |
| Estimated deal value + revenue metrics | not planned (no column) | **Not in our plan**: D2 |
| Lead detail: header, inline status/priority/assignee change | 4 | **In our MVP** (as drawer or page → D3) |
| Activity timeline with type icons, outcome | 4 | **In our MVP** |
| Log call/email/meeting/SMS/other | 4 | **In our MVP** |
| Notes | 4 | **In our MVP** |
| Schedule follow-up (type, assignee, date, time, notes), mark done | 4 | **In our MVP** |
| Follow-ups page with Overdue/Today/Upcoming/Completed pills | 4 | **In our MVP** |
| Un-complete a follow-up (checkbox toggle back) | 4 | **In our MVP** (small) |
| Follow-up types demo / check-in / proposal | 4 | **In our MVP** (enum values only) |
| Tasks entity (type exists; no UI) | none | **Not in our plan**: drop (the prototype doesn't use it) |
| Import wizard: upload, preview, map, validate, duplicate strategy, preview, result | 6 | **In our MVP** (needs staging, see §6 and D7) |
| Import history | 6 (`imports` table) | **In our MVP** |
| Sample template download | 6 | **In our MVP** |
| "Load pre-built sample list" | none | **Not in our plan**: reject (demo only) |
| Reports page: won revenue, pipeline, win rate, avg deal, funnel bars | Reports route exists, not in a step | **Phase 2** (D5) |
| Sales rep scorecard / follow-up compliance | none | **Phase 2** (D5) |
| Users list, deactivate/reactivate | 2 | **In our MVP** |
| Create user with admin-typed password + credentials card | 2 | **In our MVP** as **invite / set-password link**: reject plaintext |
| Admin password reset | 2 | **In our MVP** as **send reset link**: reject typed password |
| Edit user role | 2 | **In our MVP** (audited) |
| Teams / departments, job title, user phone | none | **Not in our plan**: D8 |
| Manager read-only team directory | 2 (`view-users`) | **In our MVP** |
| Role & permission matrix (static help table) | none | **Phase 2** (optional help text) |
| Settings: statuses add/rename/color/reorder/enable/won-lost/delete | 2 | **In our MVP** (delete only if unused) |
| Settings: status description | none | **Not in our plan**: D9 (small) |
| Settings: sources add/enable; system sources protected | 2 | **In our MVP** (`is_system` → §6) |
| Settings: tags add/delete (+ color) | 2 | **In our MVP** |
| Settings: "Sales can view all leads" toggle | 2/3 (`sales_can_view_all_leads`) | **In our MVP** |
| Settings: duplicate rules (read-only display) | 3 | **In our MVP** |
| Settings: default status / default source | none | **Not in our plan**: D9 |
| Settings: company name | none (APP_NAME) | **Not in our plan**: reject (product name lives in `APP_NAME`) |
| Reset demo database button | none | **Not in our plan**: reject |
| My profile: edit name, phone, title | 1 (profile: password + 2FA) | **In our MVP** for name; phone/title → D8 |
| Audit log viewer, 2FA, password change | 1/2/7 | ours only (the prototype has none) |
| Toasts after actions | 1 (`<x-flash>`) | **In our MVP** (the prototype mostly lacks feedback) |

---

## 6. Data model gaps

Compared with our migrations (`2026_10_01_00000{1,2,3}`), enums and seeders. **Proposals only, none
created.**

| # | Prototype has | We have | Proposal |
|---|---|---|---|
| G1 | `Lead.estimatedValue` (number, USD) | **Done (2026-10-07):** `leads.deal_value` + `leads.currency` | Per-lead currency, see "Deal value and currency" at the top. Migration `2026_10_07_000001_add_deal_value_to_leads_table`. |
| G2 | `User.team`, `title`, `phone`, `avatar`, `invitedBy`, `tempPasswordIssued` | name, email, role, is_active, last login | *If D8 = yes:* `users.job_title string(150) null`, `users.phone string(50) null`; teams either `users.team string(100) null` (simple) or `teams` table + `users.team_id` FK `->index()`. No avatar (initials). No temp-password flag (invite links instead). Optionally `users.invited_by` FK `->index()` (or rely on the audit log). |
| G3 | `LeadStatusConfig.description` | no column | `lead_statuses.description string(255) null` (D9). |
| G4 | `LeadSourceConfig.isSystem` (Website, Manual, CSV Import can't be removed) | no column | `lead_sources.is_system boolean default false`; seeder marks Website / Manual Entry / CSV/Excel Import. Import and manual create rely on these existing. |
| G5 | Follow-up types `demo`, `check-in`, `proposal`; separate date + time | `FollowUpType`: call, email, meeting, other; `due_at` timestamp | **No migration**: add enum cases `Demo`, `CheckIn`, `Proposal` (keep `Meeting`, which the prototype lacks). Form splits `due_at` into date + time inputs. |
| G6 | Activity types `status_change`, `reassign`, `import`, `follow-up` | `ActivityType::System` + `meta` jsonb; `FollowUp` | **No migration**: system activities carry `meta.event` (`status_changed`, `assigned`, `imported`, `follow_up_*`). |
| G7 | Settings `defaultStatus`, `defaultSource` | settings table (key/jsonb) | **No migration**: setting rows `default_lead_status_id`, `default_lead_source_id` (D9). |
| G8 | Settings `duplicateCheckCriteria`, `allowDuplicateCreationWithWarning` | `allow_duplicate_override` | Criteria fixed (normalized email + phone, primary and secondary). No change. |
| G9 | Import wizard keeps parsed rows in memory across 6 steps | `imports` stores stats + mapping only; the filesystem is ephemeral and files must be discarded within the request | Staging for a multi-request wizard: `import_rows` (`id`, `import_id` FK `->index()` cascade, `row_number int`, `data jsonb`, `errors jsonb null`, `duplicate_lead_id` FK null `->index()`), filled at upload (file deleted in the same request), deleted when the import completes or is abandoned (purged opportunistically on the next import, since there's no cron). Alternative: `imports.rows jsonb` (simpler, capped at 5,000 rows). Both hold lead contact data, so access goes through the import's owner + `import-leads` gate. See D7. |
| G10 | `Task` entity | none | **Don't add** (the prototype has no UI for it). |
| G11 | Lead "secondary" email/phone duplicate matching | `email_normalized`, `phone_normalized` (primary only) | Optional: `secondary_email_normalized`, `secondary_phone_normalized` + indexes, if secondary values should also trigger duplicate warnings (the prototype matches them). |
| G12 | Status colors as Tailwind names | hex `string(7)` | No change; we keep hex (better). |
| G13 | Lead ID `LEAD-1001` (random 4-digit) | `reference` `LD-000123` from id | No change; keep ours (immutable, collision-free). |

---

## 7. Things we must NOT copy as-is

| # | Prototype behaviour | Why it's a problem | How we do it instead |
|---|---|---|---|
| S1 | **Mock login** (`CRMContext.tsx:300-350`): matches by email, email prefix **or name**; **skips the password check if it's left blank** (`:327`); accepts `password123` for anyone (`:330`); case-insensitive password compare. Error text reveals the default password. | Anyone can sign in as anyone. | Fortify login (done): hashed passwords, generic errors, 5/min throttle, inactive users blocked, 2FA. |
| S2 | **Auth state in `localStorage`** (`crm_auth_session_v1 = 'true'`) | Trivial bypass from devtools. | Server session in the database, `auth` middleware on every route. |
| S3 | **Public "Create Admin Account"** | Anyone becomes admin. | No registration. First admin via `php artisan crm:create-admin`; users created by admins only. |
| S4 | **1-click test logins**, default password printed on the login page and forgot-password modal | Credential disclosure. | Remove entirely. No user is ever seeded with a known password. |
| S5 | **Role switcher** in sidebar (`switchUserById`) | Impersonate any user. | Remove. (If impersonation is ever wanted: admin-only, audited, Phase 2+.) |
| S6 | **Plaintext passwords** stored in browser storage, typed by the admin, displayed and copyable in a "credentials card" and invitation text; weak defaults (`Welcome123!`, `Math.random` generator) | Passwords leak via screen, clipboard and chat. | Admin creates the user → system emails a one-time **set-password link** (password-reset broker). Admin "reset" = send a reset link. Passwords never shown. Audit `user.created`, `user.password_reset_sent`. |
| S7 | **All authorization client-side** (`canManageUsers`, `canExport`… booleans; Settings page checks the role inside the component; Reports & Bulk Import open to every role) | Hidden ≠ protected. | Gates/policies on every route and action (`manage-users`, `manage-settings`, `export-leads`, `import-leads`, `LeadPolicy`); `AccessControlTest` and feature tests per rule. |
| S8 | **Every user's browser holds every lead**; visibility is a client-side `filter()`; Reports uses unfiltered `leads` | Sales staff can read all client data. | Server-side lead visibility scope on every query (step 3). Pages only receive the rows they may see. |
| S9 | Default visibility: sales sees **assigned + unassigned** leads (`canViewLead`) | May be broader than intended. | Our scope: sales sees assigned (+ all if `sales_can_view_all_leads`). Unassigned visibility for sales → D10. |
| S10 | **Any role can reassign** (detail assignee select, bulk assign) and bulk-change status/tags, contradicting the prototype's own matrix | Privilege escalation. | `LeadPolicy::assign` (manager/admin), `update` checks visibility; bulk actions authorize each lead. Audit assignments. |
| S11 | **Hard delete, no confirmation** (detail + bulk) | Data loss, no trace. | **Archive** (soft delete) with `<x-confirm-dialog>` naming the lead; admin only; audited. |
| S12 | **Client-side CSV export**: no formula escaping, no audit, data URI | CSV/formula injection; untracked data exfiltration. | Server-side streamed export behind `export-leads`, visibility scope applied, cells starting with `= + - @` (and tab/CR) prefixed with `'`, audit `leads.exported` with filters + count (no contact data in logs). |
| S13 | **Client-side import parsing with `xlsx` 0.18.5** (npm SheetJS build has published CVEs: prototype pollution, ReDoS); minimal validation; "update" strategy spreads raw mapped values over the lead; statuses/sources set from free text | Vulnerable dependency; unvalidated writes. | Parse on the server (package choice is a step-6 decision; it must be maintained), size and row limits, per-row validation with the same rules as the lead Form Request, whitelisted target fields, status/source matched to existing records, audit `leads.imported`, file deleted in-request. |
| S14 | Duplicate phone match on **last 7 digits** | False positives across countries. | Match on `phone_normalized` (full normalized number) + `email_normalized`. |
| S15 | Random 4-digit lead IDs | Collisions, guessable. | `reference` from DB id (immutable); routes use authorization, never trust the ID. |
| S16 | No audit trail (only import history) | Rule 6. | Audit helper for auth, user/role, settings, exports, imports, archives, assignments. |
| S17 | Website rendered straight into `href` | `javascript:` URLs in Blade would be stored XSS. | Validate `url` with an `http`/`https` scheme in the Form Request; render with `rel="noopener noreferrer"`. |
| S18 | Google Fonts CDN, Unsplash avatars, inline `style` width bars | Breaks our strict CSP; third-party requests leak usage. | System font (or a self-hosted font file via Vite if D1 picks Plus Jakarta Sans); initials avatars; bar widths via a fixed set of classes or `<progress>`/`<meter>`, no `style=""`. |
| S19 | `GEMINI_API_KEY` in `.env.example`, `@google/genai`, `express`, `dotenv` deps | API keys must never be in frontend code. | Don't copy. Any future AI feature is server-side only, key in Render env vars. |
| S20 | Dates computed in the browser; `isDueToday` compares a UTC date with a local date | Off-by-one "Due today"/"Overdue". | Computed in SQL/PHP in the app timezone at query time (`pending AND due_at < now()`). |
| S21 | Errors as one banner / `alert()`; no validation messages next to fields | UX rule. | Form Requests with plain-language `messages()`, errors next to fields. |
| S22 | `resetToSeedData` button | Wipes data. | No such feature in production. `DemoSeeder` refuses production. |

---

## 8. Frontend approach recommendation

Constraint either way: no public JSON API. Session-authenticated Inertia page props are acceptable.

### (a) Keep Blade + Alpine (CSP build) and rebuild the screens in Blade

- **Design match:** very high. The prototype is ~95% Tailwind utility classes on plain markup, and
  Tailwind 4 is already in our build, so classes port nearly 1:1 into Blade components. Pixel parity
  is achievable except where our UI rules deliberately differ (D1).
- **Interactivity:** small Alpine components in `resources/js/app.js`: dropdown menus, bulk-select
  bar, drawer/modal open/close (`<dialog>`), `/` search shortcut, kanban drag-and-drop (native HTML5
  DnD posting a form, or SortableJS later if needed), import stepper. Filters, sort and pagination are
  query strings (deep-linkable, and the back button works, which the prototype can't do).
- **Effort:** lowest. Roughly the planned steps 2–6, plus about 1–2 days to restyle the existing 14
  components and add ~6 new ones (modal, drawer, stat, filter pills, bulk bar, steps). Live search /
  live duplicate checks are the only parts needing extra endpoints (HTML fragments, authenticated) or
  a submit-time fallback.
- **Step 1 work redone:** almost none. Components get restyled; layout, auth views, profile/2FA,
  CSP, `AccessControlTest`, policies and tests all stay.
- **Security:** best. Everything is server-rendered behind auth + policies; strict nonce CSP
  unchanged; no client data store; no serialization of models into JS.

### (b) Laravel + Inertia + React, reusing the prototype's components

- **Design match:** highest in theory (same JSX). In practice every component reads and writes the
  mock `useCRM()` store directly (60+ store functions), so each one must be rewired to props + Inertia
  `useForm`/`router` calls, and their client-side logic (filtering, sorting, permissions, duplicate
  checks, import parsing) has to move to the server anyway. Probably 40–60% of each file changes.
- **Effort:** higher. Add `inertiajs/inertia-laravel` (composer) + `@inertiajs/react`, `react`,
  `react-dom`, the TS toolchain and SSR or none; build an Inertia layout and shared props; **rewrite** the
  auth/forgot/reset/2FA-challenge/profile views and all 14 Blade components in React; adapt CSP (Vite
  React works with nonces; Inertia's `data-page` JSON is fine); rewrite `AccessControlTest` checks for
  Inertia responses. Estimate +4–6 days before feature work, and it contradicts CLAUDE.md "No SPA,
  no React/Vue" (needs a rule change).
- **Step 1 work redone:** layout, all components, auth screens, profile/2FA screens, flash handling,
  Alpine setup; partly the CSP and some tests.
- **Security trade-offs:** server authorization still applies, but (1) props serialize data into the
  page, so it's easy to over-share (full models, hidden fields, other users' data); every prop needs
  an explicit Resource/array; (2) shared props go to every page; (3) client-side role checks are
  tempting and look "done"; (4) a larger JS supply chain; (5) Inertia partial-reload XHRs return JSON
  (session + CSRF protected, allowed by our rule, but more surface to test).

### Recommendation: **(a) Blade + Alpine**

The value of the prototype is its **visual design and flows**, not its React code: the markup is
plain Tailwind that ports straight into Blade, and the JavaScript is almost all mock state
(localStorage store, fake login, client-side permissions and filtering) that we'd have to throw away
under either option. Rebuilding in Blade gets a near-identical look while keeping our strict CSP,
server-side authorization, existing tests and all of step 1. The few genuinely interactive pieces
(kanban drag-and-drop, drawer, dropdowns, bulk bar, import stepper) are small Alpine components.
Option (b) would cost several extra days, reverse a project rule, and add data-exposure risk through
page props, without making the result look any better.

---

## 9. Screenshots

Taken by running the prototype locally and sandboxed: `npm install --ignore-scripts` inside `design/`
only (no lifecycle scripts, no secrets, no `.env`), Vite bound to `127.0.0.1`, then headless Chrome via
`puppeteer-core` (installed with `--no-save`). The server was stopped afterwards. The browser loaded
Google Fonts and Unsplash images from the internet, which only affects these screenshots. Data shown is
the prototype's fake seed data.

| File | Screen |
|---|---|
| 01-login, 02-signup, 03-forgot-password-modal | Auth |
| 04-dashboard-admin, 41-dashboard-sales-role | Dashboard |
| 05-global-search, 06-follow-up-alerts | Header search dropdown, alerts bell |
| 07-leads-table … 10-leads-bulk-status-menu, 12-leads-empty-search, 42-leads-sales-role | Lead list, filters, bulk bar, empty state |
| 11-leads-kanban | Pipeline board |
| 13 … 16-lead-detail-* | Lead drawer: timeline, notes, follow-ups, contact |
| 17-log-activity-modal, 18-schedule-follow-up-modal | Drawer sub-modals |
| 19-lead-edit-form, 20-lead-create-form, 21-lead-create-duplicate-warning | Lead form |
| 22 … 24-follow-ups-* | Follow-ups queue, schedule modal, completed |
| 25 … 31-import-* | Import steps 1–6, history |
| 32-reports | Reports |
| 33-users, 34-users-invite-modal, 35-users-edit-modal, 43-users-manager-readonly | Users |
| 36 … 39-settings-* | Settings tabs |
| 40-my-profile-modal | Profile |

The header search box still shows "ster" in some later shots (a leftover from shot 05). It's cosmetic.

---

## Decisions (all settled, 2026-10-07)

| # | Question | Decision |
|---|---|---|
| D1 | Copy the prototype's look exactly, or keep our UI rules where they differ? | **Copy the prototype exactly** (indigo accent, Plus Jakarta Sans, its sizes, labels, cards and shadows, layout). CSP still applies, so the font is self-hosted and nothing loads from a CDN. CLAUDE.md "UI rules" must be updated to match. |
| D2 | Deal value and currency? | **Per-lead `deal_value` + `currency`**, with `enabled_currencies` / `default_currency` settings. See "Deal value and currency" at the top. |
| D3 | Lead detail as a slide-over or a full page? | **Slide-over panel with its own URL** (deep links and the back button work). |
| D4 | Kanban board: MVP or later? | **MVP, step 3.** |
| D5 | Reports: who sees it, and when? | **Admin and manager**, step 5, visibility-scoped. |
| D6 | Live-as-you-type search and duplicate warnings, or on submit? | **As-you-type search**, returning only leads the user may see (same visibility scope and policy as every other lead query). |
| D7 | Where the import wizard keeps rows between steps | **Encrypted `import_rows` table**; the uploaded file is deleted in the upload request. |
| D8 | Teams, job title, phone; do teams limit visibility? | All three are in. **Teams do not limit which leads a manager sees**, for now. |
| D9 | Status description, default status / default source | **Included.** |
| D10 | Can Sales see unassigned leads? | **Yes, as a setting that defaults to on.** |
| D11 | Bulk tagging, permissions table, reopen follow-up | **All included.** |
| D12 | What to do with `design/` | **Stays on disk, gitignored** (`/design/` in `.gitignore`); only this audit and the screenshots live in our repo. |
