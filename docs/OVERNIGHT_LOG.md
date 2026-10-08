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
| 3 | Not started | |
| 4 | Not started | |
| 5 | Not started | |
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

## Decisions made overnight

## Needs Kartik
