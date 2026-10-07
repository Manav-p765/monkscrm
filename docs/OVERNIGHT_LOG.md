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
| 2 | In progress | |
| 3 | Not started | |
| 4 | Not started | |
| 5 | Not started | |
| 6 | Not started | |

## Decisions made overnight

## Needs Kartik
