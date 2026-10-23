<?php

namespace App\Support;

use App\Models\Integration;

/**
 * The "AI agent prompt" on an integration's page: everything a coding agent (Claude Code, Cursor,
 * Copilot…) needs to connect a website's forms to this integration, so the person only copies and
 * pastes it. It contains the secret key, so the page says where (not) to paste it.
 */
class IntegrationPrompt
{
    public static function for(Integration $integration): string
    {
        $url = $integration->intakeUrl();
        $secret = $integration->secret;
        $browser = $integration->allow_browser
            ? 'Browser posts are ON for: '.implode(', ', $integration->allowed_origins ?? []).'. Posts from any other site are refused.'
            : 'Browser posts are currently OFF. Tell me to turn on "Accept posts straight from a web page" for this form in the CRM (Settings → Integrations → Edit) and to add this site\'s address under "Allowed websites", e.g. https://example.com.';
        $thanks = $integration->redirect_url
            ? "A plain HTML form post is redirected to {$integration->redirect_url}."
            : 'A plain HTML form post shows a simple "Thank you" page (or set a thank-you page in the CRM).';

        return <<<PROMPT
            Connect this website's lead / contact / enquiry form(s) to our CRM, so every submission also creates a lead in the CRM. Keep everything the forms do today (emails, notifications, success messages, design); only add the CRM call.

            ## CRM connection ("{$integration->name}")
            - Endpoint: POST {$url}
            - Secret key: {$secret}
              Treat it like a password: store it ONLY as a server-side environment variable named CRM_FORM_SECRET (.env / hosting env vars / wp-config.php). Add CRM_FORM_SECRET= (empty) to .env.example. Never commit it, never log it, never send it to the browser (in Next.js: no NEXT_PUBLIC_ prefix).

            ## Steps
            1. Find every lead-type form on this site and the code that handles its submit.
            2. Choose the method by stack:
               A. The site has a server (Next.js route handler / server action, Express / Node API, PHP, Laravel, Django…): after the existing handling succeeds, send the submission from the SERVER to the endpoint as JSON, signed with the secret:
                  - Headers: Content-Type: application/json, Accept: application/json, X-CRM-Timestamp: <unix seconds>, X-CRM-Signature: sha256=<hex HMAC-SHA256 with the secret as key, of "<timestamp>.<raw JSON body>">.
                  - Sign the exact body bytes you send. Timestamps older than 5 minutes are refused.
                  - (Simpler alternative if signing is impractical: header Authorization: Bearer <secret>.)
               B. WordPress: add a small must-use plugin (wp-content/mu-plugins/crm-leads.php) that hooks the form plugin in use (Contact Form 7: wpcf7_mail_sent; WPForms: wpforms_process_complete; Gravity Forms: gform_after_submission; Elementor Pro: elementor_pro/forms/new_record) and sends the fields with wp_remote_post( endpoint, [ 'headers' => [ 'Authorization' => 'Bearer ' . CRM_FORM_SECRET, 'Accept' => 'application/json' ], 'body' => \$fields, 'timeout' => 60 ] ). Define CRM_FORM_SECRET in wp-config.php, not in the plugin file.
               C. Static site with no server (plain HTML, a Vite/React app without a backend): post from the browser WITHOUT the secret: fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(fields) }) or a normal <form method="POST" action="endpoint">. {$browser} {$thanks}
               If a site has a backend, always use A, never C.
            3. Field names (case and "your-" prefixes don't matter): name (or first_name + last_name), email, phone, company, job_title, website, city, state, country, message. An email or a phone is required. Extra fields (service, budget, …) are welcome: they're kept on the lead's timeline. Never send status, source, assigned_to or tags (the CRM ignores them).
            4. Ad tracking: on landing, read utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid, msclkid and li_fat_id from the URL and keep them for the visit (sessionStorage or a first-party cookie), then include them in every submission, plus page_url (the page the form is on) and referrer (document.referrer).
            5. Spam trap: add a hidden input named _hp to each form (visually hidden, tabindex="-1", autocomplete="off", aria-hidden="true") and send its value as _hp. Real visitors leave it empty.
            6. Reliability: the CRM can take up to ~60 seconds to answer when it has been idle (free hosting waking up). Use a timeout of at least 60 s and retry once on a network error, 5xx or 429. Don't make the visitor wait for the CRM if you can avoid it (e.g. Next.js after() / waitUntil, or respond first and send after), but log failures (without the visitor's personal data or the secret).
            7. Responses: 201 {"ok":true} = received (also when the lead already existed); 401 = wrong secret / signature / not an allowed site; 404 = wrong endpoint or the form is turned off in the CRM; 422 {"ok":false,"errors":[…]} = no email or phone; 429 = too many posts.
            8. When done: list the files you changed and the env var(s) I must set on the hosting provider, then ask me to submit each form once with test data and check "Recent Submissions" on this form's page in the CRM.
            PROMPT;
    }
}
