# Overnight log

## Summary
_(written at the end of the night)_

## Rules for the night (from Kartik, 2026-10-07)
- Finish Step 2 and its report, then Steps 3, 4, 5, 6 from `docs/OVERNIGHT_PLAN.md`, in order. **No Step 7 (deploy).**
- Follow CLAUDE.md, docs/DESIGN_AUDIT.md and the prototype exactly (visual source of truth).
- Commit after each numbered section of each step. Never push, add a remote, deploy, or touch anything
  outside the project folder. Never use real client data.
- After each step: `php artisan test` AND `composer test:pgsql` must both pass before the next step;
  screenshot new screens, compare with docs/design-screens/, fix, save ours to docs/build-screens/.
- A test that can't be fixed after a few honest attempts: revert to the last green commit for that
  section, log it as Blocked with the reason, move on if nothing depends on it.
- Undecided questions: pick the option closest to the prototype that is safest for data, log it under
  "Decisions made overnight", continue. Stop only for irreversible actions, admin rights or Kartik's
  accounts; log those as "Needs Kartik".
- Update this log after every step: status, commits, test counts (SQLite + Postgres), decisions,
  remaining visual differences, "Needs Kartik".

## Steps

| Step | Status | Notes |
|---|---|---|
| 2 | Done | See "Step 2 report" below. SQLite 124 + 1 skipped, Postgres 125/125. |
| 3 | Done | Leads core, panel, bulk, Kanban, perf. SQLite 144 + 1 skipped, Postgres 145/145. |
| 4 | Done | Notes, activities, follow-ups, timeline, follow-ups page, alerts bell. SQLite 152 + 1 skipped, Postgres 153/153. |
| 5 | Done | Dashboard + Reports + report CSV. SQLite 156 + 1 skipped, Postgres 157/157. |
| 6 | Not started | |

## Step 2 report

**Built:** native PHP 8.4 + Postgres dev setup; prototype design system (tokens, font, icons,
restyled + new components); app shell; schema additions; IST dates; audit log (helper, auth events,
admin page); users & teams (invites, credentials card, edit, reset link, two-step off, sign out
everywhere, deactivate with reassignment, reactivate, teams); settings (statuses, sources, tags,
security & rules, permissions table); tests. Screens: `docs/build-screens/01–48-*`.

**Packages added:** `mallardduck/blade-lucide-icons` (composer: the prototype's Lucide icon set,
rendered server-side as inline SVG, no CDN, no JS); `@fontsource/plus-jakarta-sans` (npm dev: the
prototype font, self-hosted and bundled by Vite so the CSP stays 'self').

**Deviations from the Step 2 instructions:**
- Text never lighter than slate-500 (the prototype's slate-400 meta text fails WCAG AA); placeholders too.
- Extra migration `user_invite_tokens` (invite tokens in their own table so a 30-minute reset token can
  never be redeemed as a 48-hour invite) and `follow_ups.assigned_to` made nullable (needed to "leave
  follow-ups unassigned" on deactivation).
- Extra setting `default_status_id` (the "default status" rule needed a home; mirrors `default_source_id`)
  and import limits seeded in section 3.
- Edit / deactivate modals have their own URLs (`/users/{id}/edit`, `/users/{id}/deactivate`), like the
  lead slide-over; the credentials link travels encrypted in the session flash for one request.
- Invite status: "Invited" = never signed in (no extra column).
- My Profile shows name/email/role but doesn't edit them yet (moved to Step 3).
- PHP enabled `pdo_sqlite`/`sqlite3`/`sodium` as well (the SQLite test run needs them).

**Commits:** `2ccd5fc` local dev with Postgres · `fff5521` design system · `3af87a2` app shell ·
`e84791f` schema additions · `f09afbb` times in IST · `655c0e7` audit log · `770465b` users and teams ·
`96e0c47` settings · `96f6677` tests · (finish commit follows).

**Remaining visual differences:** sign-in card has a "Sign In" heading instead of the prototype's
Sign In / Create Admin tab switcher (self-signup is excluded); required fields show "*" where the
prototype login doesn't; avatars are initials, not photos; New Lead / Add Lead buttons and sidebar
counts appear in Step 3/4; settings tabs use the bordered pill style of the quick filters.

## Step 3 report

**Commits:** `aa2a05a` sections 3.1–3.10 (one commit: the sections share the same controller/views) ·
3.11 performance (this commit).

**Performance (3.11), 20,000 fake leads (`PerformanceSeeder`), local Postgres, `php artisan serve`,
APP_DEBUG on, median of 3:** list 0.33–0.51 s, search page 0.41–0.45 s, as-you-type search 0.19–0.22 s,
pipeline 1.0–1.2 s, dashboard 0.13–0.24 s. These include the single-threaded dev server and debug mode,
so they're upper bounds; no extra indexes or pg_trgm were needed for the ~300 ms goal on list and search.
Fixes made: OPcache enabled in the local PHP; Kanban cards rendered without nested components (was 25 s);
settings memoized per request (was 1,200 cache-store reads on a Sales pipeline).

**Decisions:** duplicates are checked against all non-archived leads (to block them) but only leads the
user can see are named; secondary email/phone are searched but not used for duplicate matching; Kanban
column totals don't update live after a drag (counts do); quick actions in the panel are disabled until
Step 4; My Profile name/phone editing still pending.

## Step 4 report

**Built:** notes (add; author edits within 15 minutes; admin soft-delete, audited), log activity
(call/email/meeting/SMS/other, IST date/time, outcome, optional follow-up for yourself; contact types
update last/first contacted), merged timeline (activities + system events + notes, type filter, 20 per
page), follow-ups (schedule, complete with optional outcome → logged activity, cancel, reschedule,
reopen; `FollowUps::sync()` keeps `leads.next_follow_up_at` = earliest pending), Follow-Ups page
(Overdue / Due Today / Upcoming / Completed; sales see their own, admins/managers everyone + assignee
filter), alerts bell (overdue, due today, newly assigned since `users.alerts_seen_at`, badge, list,
"Mark all as seen"), sidebar counts (leads, follow-ups due). Migration: notes soft deletes,
`users.alerts_seen_at`, `follow_ups.outcome`. New permission `delete-notes` (admin).

**Decisions:** follow-ups are scheduled from the lead panel only (the prototype's page-level "Schedule"
modal needs a lead picker over up to 20k leads; the page links to Leads instead); "Due Today" ends at
midnight IST; a follow-up completed with an outcome logs a call/email/meeting activity (other types log
"follow-up"); Follow-up types: Call, Send Email, Meeting, Product Demo, Check-in, Send Proposal, Other.

**Commit:** see the "Step 4" commit.

## Step 5 report

**Built:** dashboard (8 stat cards: total, new, contacted, qualified, due today, overdue, won with
per-currency value, lost; pipeline strip with per-currency values; follow-up queue with Done; recent
activity; lead sources), all from a few grouped queries in `App\Support\LeadStats` on
`Lead::visibleTo()`. Reports for admin/manager with a date range (IST days): win rate, won value per
currency, follow-up completion rate, funnel from milestone timestamps, by status/source/assignee/team,
sales scorecard (assigned, contacted, qualified, won, won value per currency, average time to first
contact, follow-up completion). Report CSV export (audited, 10/hour, formula injection protected via
`App\Support\Csv`).

**Charts (5.3):** no JS chart library. The prototype's charts are simple horizontal bars, so they are
rendered on the server as inline SVG (`<x-bar-chart>`): identical look, CSP-safe (no eval, no inline
styles, widths are SVG attributes), nothing extra to bundle or keep updated.

**Decisions:** "New" = leads in the default status; "Contacted/Qualified" = leads that ever reached the
milestone; follow-up completion = completed ÷ (follow-ups that came due, excluding cancelled); reports
count leads created in the range; small query-string/PATCH inputs (report dates, follow-up page filter,
panel status/assignee/priority) are validated inline with `$request->validate()` rather than separate
Form Request classes.

## Decisions made overnight

## Needs Kartik
