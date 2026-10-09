# Pre-launch review (2026-10-09)

Review of Steps 2–6 (built overnight without human review), verified by reading code, tests and a
real browser on demo data. Commits: `Review: security` 6459fb0, `Review: code rules` bc6dc1c,
`Review: data correctness` 00e5de5, `Review: demo data` 740861d, `Review: visual` 6f41ef3.

## 1. Security

| # | Finding | Severity | Fix |
|---|---|---|---|
| S1 | Import validation errors (stored **unencrypted** in `import_rows.errors`) quoted the invalid email / phone | Medium | Messages no longer quote contact values; originals stay in the encrypted row data |
| S2 | No rate limit on forgot-password, reset-password, confirm-password, change-password, 2FA setup (email flooding, password guessing by a session holder) | Medium | `auth-forms` limiter: 10/min per IP and form |
| S3 | Login limited only per email+IP (credential stuffing across many emails from one IP unlimited) | Low–Medium | Extra 30/min per IP |
| S4 | `leads.lead_status_id`, `assigned_to`, `next_follow_up_at` were mass-assignable | Low (no caller passed request data, but the services could be bypassed) | Removed from `$fillable` |
| S5 | Export audit entry stored the raw search text (can be an email/phone) | Low | Logged as `(search)` |
| S6 | Live search / duplicate check would inject the sign-in page into the dropdown after session expiry | Low (UX) | Redirected responses are ignored |
| S7 | Lead website rendered as a link without a scheme check in the view (input is validated; defence in depth) | Low | Only http(s) becomes a link |
| S8 | Duplicate check reveals that *some* lead has an email/phone, even one the user can't see | Low, by design | Kept (needed to block duplicates); no details leak, rate-limited 60/min |

Verified and fine: visibility scope on every lead query (lists, search, counts, dashboard, reports,
pipeline, alerts, follow-ups, timeline, notes, imports, export, dropdowns); route binding 404s for
leads, notes, follow-ups and imports outside visibility; CSRF on all 57 state-changing routes (test
switches the CSRF check back on); no state change on GET (reviewed GET list in a test); no `{!! !!}`
with user content, no `x-html`; CSV formula escaping on all exports; upload extension + MIME + macro
checks; upload deleted after parsing; import rows and 2FA secrets encrypted; invite/reset links single
use with expiry and no enumeration; CSP and security headers on pages, fragments and error pages.

Tests: `tests/Feature/SecurityReviewTest.php` (IDOR per record type, CSRF, GET list, XSS, headers,
encryption, two-step sign-in challenge, rate limits, invite enumeration).

### Routes

98 routes. All non-public routes require auth (`AccessControlTest`); every role-restricted route has
`can:` middleware or a policy/Form Request check. After this review only these have no functional
test (all Fortify internals or a redirect): `GET /` (redirect to dashboard),
`GET user/confirmed-password-status`, `POST user/two-factor-recovery-codes` (regenerate).

## 2. Code rules

- Inline validation moved to Form Requests (panel status/assignee/priority, report dates, follow-up
  filter, live search, duplicate check, import mapping/strategy, invite form).
- Status changes: all through `LeadStatusChanger`. Assignment: deactivating a user bypassed
  `LeadAssigner` (bulk update, no timeline/audit/alert); fixed. New leads (form, import) now get their
  assignee through `LeadAssigner` so the assignee is alerted.
- Lead search used hand-written `like`/`whereLike`; now the `Searchable` helper.
- Dead code removed (`PlaceholderController` + view); "end of today in IST" deduplicated; no debug code.
- Guarded by `tests/Feature/CodeRulesTest.php`.

## 3. Data correctness

`tests/Feature/DataCorrectnessTest.php`: 31 hand-counted leads moved through the real status
changer. Every dashboard card, pipeline figure, funnel step, scorecard, follow-up rate and report CSV
row matched the hand count for admin, manager and both sales users. Milestones are never overwritten;
times are IST in the UI and CSV; money is always grouped by currency.

Fixed: the importer could put leads in a turned-off status/source; "create unknown" crashed on a
turned-off name with different case; the importer relied on mass-assignment protection to drop
internal keys (crashed in seeders/tinker).

**Decisions for Kartik** (numbers are correct for the current definitions, but the definitions may
not be what you want):
- *Contacted* = "a call/email/meeting/SMS was logged or the lead reached the Contacted status". A
  call logged as "No answer" counts, so Attempted Contact leads show as contacted (66 of 90 in the demo).
- The funnel is not cumulative: a lead moved straight from New to Won counts as Won but not as
  Contacted/Qualified, so a later step can be larger than an earlier one.

## 4. Visual (docs/build-screens/review/ vs docs/design-screens/)

Fixed: live search, panel Escape, drag reorder, import Start button and modal × (all broken by one
Alpine `$el`/`$root` mistake), report bars and missing cards, lead list Deal Value off-screen,
follow-ups tab/rows, alerts panel layout, settings chip and tabs, import chip.

Remaining differences (deliberate or minor):
- Sign-in has no Sign In / Create Admin tabs (self-signup excluded); initials instead of photos.
- Dashboard: 8 stat cards (prototype 4); stage strip wraps to 2 rows of 4 (two currencies don't fit in 8 columns).
- Pipeline is its own page with its own title; column totals on a second line; "Move to…" select instead of arrow buttons; totals refresh on reload.
- Follow-ups page has no "Schedule Follow-Up" button (scheduled from the lead).
- Extra controls: filter bars (users), timeline type filter, archive instead of delete, header "More" menu.
- Text never lighter than slate-500 (contrast).
- Reports: 6 cards (prototype 4 + leads created + follow-up completion).

## 5. Demo data

`php artisan migrate:fresh --seeder=DemoSeeder` (local only): admin, manager, 3 sales users, 80
leads + 1 finished import of 10, all statuses, USD and INR, notes, activities, follow-ups (overdue,
due today, upcoming, completed). Fake data only. Logins print to the console once (random password
per run). The demo admin must set up two-step sign-in at first sign-in (Step 7 rule).

## Would not go live without

1. A manual click-through of every interactive screen in the deployed build. There are no browser
   tests; five JS features were silently broken while all PHP tests passed.
2. `TRUSTED_PROXIES=*` on Render (docs/DEPLOY.md). Without it every user shares the proxy IP and the
   new per-IP limits would lock out the whole office together.
3. A decision on the two "Contacted"/funnel definitions above before anyone reports on them.
