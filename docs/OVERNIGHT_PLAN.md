# Overnight plan (Steps 3–6)

Saved verbatim from Kartik's overnight instructions (2026-10-07). Progress, decisions and blockers
are logged in `docs/OVERNIGHT_LOG.md`.

GLOBAL RULES FOR ALL STEPS
- One visibility scope, Lead::visibleTo($user), used by EVERY lead query: lists, search, counts, dashboard, kanban, reports, duplicate checks, exports, dropdowns.
  - Admin and manager: all leads (teams don't limit visibility).
  - Sales: leads assigned to them, plus unassigned leads if sales_can_view_unassigned is on, plus all leads if sales_can_view_all_leads is on.
- A lead outside a user's visibility returns 404 (not 403), so its existence isn't revealed.
- All status changes (form, panel, bulk, kanban, import) go through one LeadStatusChanger service. It:
  - stamps milestone timestamps (first_contacted_at, qualified_at, meeting_booked_at, proposal_sent_at, won_at, lost_at) only the first time
  - writes a system activity ("Status changed from X to Y")
  - writes the audit log
- Assignment changes go through one service that writes a system activity and the audit log.
- As-you-type endpoints return server-rendered HTML fragments, not JSON. They require login, are scoped by visibility, rate-limited to 60/min per user, and need at least 2 characters.
- Use <x-date> and <x-money> everywhere. Everything is styled like the prototype.

STEP 3: LEADS CORE + KANBAN
3.1 Lead list matching the prototype:
  - the prototype's columns, quick filters and sorting
  - filters: status, assigned to, source, priority, tags, created date, follow-up date, last activity, city, state, country, currency
  - filter state kept in the URL query string, "Clear filters", 25 per page, and empty states
3.2 Header search as you type:
  - fields: name, company, email, phone (compared as digits only), reference
  - max 8 results; Enter opens the full list filtered by the search
3.3 Create/edit form matching the prototype:
  - Required: first name or company, plus phone or email. Store the normalized email/phone.
  - Deal value with a currency selector (enabled currencies only; default from settings).
  - Default status and source come from settings.
3.4 Duplicate check as you type (on normalized email/phone):
  - If the user can see the matching lead: show its name with a link.
  - If not: show only "A lead with this email/phone already exists", with no details.
  - On save: block duplicates for sales. Admins and managers may override if allow_duplicate_override is on; log lead.duplicate_override.
3.5 Slide-over lead panel exactly as in the prototype:
  - URL /leads/{reference}. A direct visit renders the list with the panel open.
  - Header with quick actions, contact info (mailto/tel links), CRM info, tags, deal value.
  - Tabs as in the prototype. Notes, activity and follow-up tabs are placeholders until step 4.
3.6 Permissions (enforced in LeadPolicy):
  - Sales can create leads and edit/change status on leads they can see, and can only assign to themselves.
  - Admins and managers can assign to anyone.
  - Archiving and restoring: admin only. Add an Archived view for admins.
3.7 Bulk actions:
  - status, assign, add/remove tag, archive (admin)
  - The server rechecks every selected ID against visibility and policy. Max 500 per action, in one transaction. Show a toast with the count.
3.8 Kanban at /pipeline, as in the prototype:
  - One column per active status, in order, with a count and per-currency totals.
  - Same filters as the list. Max 50 cards per column, then "Show more".
  - Drag with native HTML5 drag-and-drop, plus a keyboard-accessible "Move to…" menu on each card.
  - A drop calls the server (LeadStatusChanger); the UI reverts if it fails.
3.9 Audit actions: lead.created, lead.updated (changed field names), lead.status_changed, lead.assigned, lead.archived, lead.restored, lead.bulk_action (action + count), lead.duplicate_override.
3.10 Tests:
  - the visibility matrix across list, search, panel, kanban, bulk and duplicate check
  - 404 for invisible leads; the duplicate check doesn't leak details
  - milestones are stamped only once
  - bulk actions recheck IDs
  - mass-assignment protection, validation, currency
3.11 Performance: seed 20,000 demo leads locally. List, search and kanban should respond in under about 300 ms on Postgres. Add indexes if needed (pg_trgm for search only if it's slow). Log the timings.

STEP 4: LEAD ACTIVITY, FOLLOW-UPS, ALERTS
4.1 Notes in the panel, as in the prototype:
  - add; the author can edit within 15 minutes
  - admins can delete (soft delete, audit-logged)
4.2 Log activity:
  - type (call, email, meeting, sms, other), date/time (IST), description, outcome, and optionally schedule a follow-up
  - Updates last_activity_at. Call/email/meeting/sms also update last_contacted_at and, the first time, first_contacted_at.
4.3 Timeline:
  - merged in date order: system events, notes, activities, follow-ups
  - type filter as in the prototype; paginated
4.4 Follow-ups:
  - schedule (type, assignee, due date/time in IST, notes), complete (optional outcome, which logs an activity), cancel, reschedule, reopen a completed one
  - "Overdue" = pending and past due, computed at query time
  - The assignee must be able to see the lead
  - One service keeps leads.next_follow_up_at equal to the earliest pending follow-up
4.5 Follow-ups page as in the prototype:
  - tabs: Overdue, Today, Upcoming, Completed
  - sales see their own; admins and managers see all, with an assignee filter
4.6 Alerts bell, computed per request (no cron):
  - overdue follow-ups, follow-ups due today, and leads newly assigned to me since users.alerts_seen_at (new column)
  - a badge count, a dropdown list, and "Mark all as seen"
4.7 Tests: permissions on every action, next_follow_up_at stays in sync, overdue logic in IST, alert counts respect visibility, reopen works.

STEP 5: DASHBOARD + REPORTS
5.1 Dashboard exactly as in the prototype:
  - stat cards (total, new, contacted, due today, overdue, qualified, won, lost), all scoped by visibility
  - pipeline strip, upcoming follow-ups, recent activity, lead sources breakdown
  - won value as per-currency totals
  - use a few aggregate queries, not one query per card
5.2 Reports (admin and manager), matching the prototype:
  - leads by status, source, assignee and team over a date range
  - a funnel built from the milestone timestamps
  - a sales scorecard per user: assigned, contacted, qualified, won, won value per currency, average time to first contact
  - follow-up completion rate
5.3 Charts: use the prototype's chart look with a CSP-safe library bundled by Vite (no CDN, no eval). Choose one and note why.
5.4 Report CSV export (admin and manager, audit-logged), with spreadsheet-formula injection protection (prefix cells starting with = + - @ with ').
5.5 Tests: the numbers match fixture data; sales get 403 on Reports; dashboard counts respect visibility; mixed currencies are never summed together.

STEP 6: IMPORT, UNDO, EXPORT
6.1 The 6-step wizard exactly as in the prototype: upload → read → map → validate → duplicates → preview → import.
  - Upload:
    - .csv and .xlsx only; check both extension and MIME type; reject .xlsm and other macro files
    - enforce the size and row limits from settings
    - parse with a maintained library (e.g. OpenSpout); delete the uploaded file immediately after parsing
    - rows are staged in import_rows (encrypted)
  - Map:
    - auto-guess columns from header names; "Do not import" option; currency column supported
    - unknown statuses, sources, tags or users → choose: use default, create (admin only), or skip the row
  - Validate: email format, phone normalization, required fields, unsupported values.
  - Duplicates (within the file and against the database, on normalized email/phone):
    - strategies: skip (default), update existing, or import as new
    - update and import-as-new are admin/manager only; update only touches leads the importer can see
  - Import:
    - no background worker exists, so process in chunks of 500 rows per request, with the page calling the next chunk ("Importing 1,500 of 4,200") until done
    - resumable if the page reloads; each chunk runs in a transaction
    - all status changes go through LeadStatusChanger
  - Finish: summary screen plus a downloadable error-rows CSV.
6.2 Undo import within 24 hours (admin, or the user who imported):
  - archives the leads that import created, if they haven't been edited since; lists any that were edited and skips them
  - updates to existing leads are not reverted; warn about this before the user chooses "update existing"
  - audit-logged
6.3 Import history page, as in the prototype: who, when, file name, counts, status, and the Undo button.
6.4 Lead export (admin and manager):
  - CSV of the current filtered list, scoped by visibility, with selectable columns
  - streamed; max 20,000 rows; rate-limited to 10 per hour per user
  - formula-injection protection; audit-logged with the filters used and the row count
6.5 Tests:
  - every wizard step and permission
  - duplicates within the file and against the DB, and each strategy
  - chunk resume
  - staged rows encrypted, and deleted on completion, cancel, or after 24 hours
  - undo
  - export scope, formula-injection protection, rate limit
