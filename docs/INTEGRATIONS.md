# Integrations: getting leads in from websites and ads

Settings → Integrations (admins). Each website form gets an integration: a **form address**
(`https://crm.hybridmonks.com/intake/in_…`) and a **secret key** (`whsec_…`). New leads get the
integration's source and Assigned to, and the default status. The integration page lists every post it
received in the last 90 days (result + field names), which is the first place to look when a form "doesn't work".

## Which way to connect

| Website / tool | How | Secret key goes in |
|---|---|---|
| Next.js, Node/Express (MERN), any backend | Sign the post on the server (snippet on the integration page) | Server env var, e.g. `CRM_FORM_SECRET` |
| WordPress (Contact Form 7, WPForms, Gravity Forms, Elementor) | A webhook add-on that can add a header, POST to the form address | Header `Authorization: Bearer <secret>` in the add-on |
| Zapier / Make (incl. **LinkedIn Lead Gen**, Typeform, …) | Trigger "new lead/submission" → Webhooks by Zapier (POST) / Make HTTP | Header `Authorization: Bearer <secret>` |
| Plain HTML or a static Vite/React site with no server | Form posts straight to the form address (or `fetch()`) | **Nowhere.** Turn on browser posts and list the site instead |

Never put the secret in browser code. For a React/Vite site with a Node backend, post from the backend.

## Field names

Matched automatically, case and punctuation ignored, prefixes like `your-` / `form_` dropped:
`name` (split into first/last), `first_name`, `last_name`, `email`, `phone`/`mobile`/`tel`/`whatsapp`,
`company`, `job_title`/`designation`, `website`, `address`, `city`, `state`, `pincode`/`zip`, `country`,
`message`/`comments`/`enquiry` (→ description).

- An **email or phone is required**. Without one the post is refused (422) and shows as Rejected with the reason.
- Everything else (service, budget, …) and `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`,
  `gclid`, `fbclid`, `msclkid`, `li_fat_id`, `page_url`, `landing_page`, `referrer` are kept on the lead's timeline.
  Pass the UTM values from the page URL into hidden fields to see which ad brought the lead.
- Status, source, Assigned to, tags and deal value are **never** taken from the form.
- If the email or phone matches an existing lead, the post is added to that lead's timeline (no duplicate).
- Hidden field `_hp` is a spam trap: if it has a value, the post is answered "ok" and dropped.

## Responses

`201 {"ok":true}` (also for an existing lead or spam: nothing leaks) · `401` wrong/missing secret, old
signature, or site not allowed · `404` unknown or turned-off form · `422 {"ok":false,"errors":[…]}` · `429`
too many posts (20/min per sender, 300/min per form). A plain HTML form post redirects to the thank-you page.

## Signing (servers)

```
X-CRM-Timestamp: <unix seconds>
X-CRM-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>
```

Refused if the timestamp is more than 5 minutes off. Bearer is simpler. Signing also protects the body
in transit and stops replays.

## Free-tier caveat

Render's free instance sleeps when idle; the first post after a quiet spell waits ~50 s while it wakes.
Server senders should use a timeout of 60 s+ and retry on failure (Zapier and Make retry by themselves).
Browser forms should show "Sending…" and not give up early. An always-on instance removes the wait.

## Roadmap

- **Meta Lead Ads** (Facebook / Instagram instant forms): one integration per Page / ad account, several per
  client; webhook `POST /webhooks/meta` verified with the app secret, lead details fetched from the Graph API
  with an encrypted Page token; "Sync now" to pull anything missed. Our own accounts first (system-user token);
  clients connect their own through "Connect with Facebook" once Meta approves the app (`leads_retrieval`).
- **Google Ads lead forms**: webhook with Google's key per form.
- **LinkedIn Lead Gen**: through Zapier/Make now (above); a direct connection needs LinkedIn partner approval.
