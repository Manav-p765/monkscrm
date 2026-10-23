@php
    $url = $integration->intakeUrl();
    $bag = 'integration'.$integration->id;
    $snippets = [
        'server' => <<<JS
            // Next.js route handler / server action, Express or any Node server (never in browser code).
            // Keep the secret in an environment variable, e.g. CRM_FORM_SECRET.
            const body = JSON.stringify({
              name: form.name, email: form.email, phone: form.phone,
              company: form.company, message: form.message,
              utm_source: form.utm_source, utm_campaign: form.utm_campaign, page_url: form.pageUrl,
            });
            const timestamp = Math.floor(Date.now() / 1000).toString();
            const signature = 'sha256=' + require('crypto')
              .createHmac('sha256', process.env.CRM_FORM_SECRET).update(timestamp + '.' + body).digest('hex');

            await fetch('{$url}', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CRM-Timestamp': timestamp, 'X-CRM-Signature': signature },
              body,
            });
            JS,
        'bearer' => <<<TXT
            POST {$url}
            Authorization: Bearer <secret key>
            Content-Type: application/json   (or form fields)

            {"name": "Asha Rao", "email": "asha@example.com", "phone": "+91 98765 43210", "message": "…"}
            TXT,
        'html' => <<<HTML
            <form method="POST" action="{$url}">
              <input name="name" required>
              <input name="email" type="email">
              <input name="phone" type="tel">
              <textarea name="message"></textarea>
              <!-- Spam trap: keep it hidden and empty. -->
              <input name="_hp" tabindex="-1" autocomplete="off" hidden>
              <button type="submit">Send</button>
            </form>
            HTML,
    ];
@endphp

<x-settings-layout>
    <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('settings.integrations') }}" class="inline-flex items-center gap-1 text-2xs font-semibold text-slate-500 hover:text-indigo-600">
                    <x-lucide-arrow-left class="size-3" aria-hidden="true" /> All integrations
                </a>
                <h2 class="mt-1 flex items-center gap-2 text-sm font-bold text-slate-900">
                    {{ $integration->name }}
                    <x-badge :tone="$integration->is_active ? 'green' : 'neutral'">{{ $integration->is_active ? 'Active' : 'Off' }}</x-badge>
                </h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ $integration->type->label() }} · New leads: {{ $integration->source?->name ?? 'default source' }}, {{ $integration->assignee ? 'assigned to '.$integration->assignee->name : 'unassigned' }}
                    · Last post: <x-date :value="$integration->last_received_at" relative empty="never" />
                </p>
            </div>
            <x-button type="button" variant="secondary" icon="edit" x-data="modalTrigger" data-modal="integration-edit" x-on:click="open">Edit</x-button>
        </div>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <span class="block text-2xs font-semibold text-slate-600">Form address (webhook URL)</span>
                <div class="mt-1 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate text-xs text-slate-800">{{ $url }}</code>
                    <button type="button" x-data="copyText" data-text="{{ $url }}" x-on:click="copy" class="flex shrink-0 items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800">
                        <x-lucide-copy class="size-3.5" aria-hidden="true" /><span x-show="notCopied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <span class="block text-2xs font-semibold text-slate-600">Secret key</span>
                <div x-data="passwordReveal" class="mt-1 flex items-center gap-2">
                    <label for="integration-secret" class="sr-only">Secret key</label>
                    <input id="integration-secret" readonly x-bind:type="inputType" type="password" value="{{ $integration->secret }}"
                           class="min-w-0 flex-1 border-0 bg-transparent p-0 font-mono text-xs text-slate-800 focus:ring-0">
                    <button type="button" x-on:click="toggle" class="shrink-0 text-xs text-indigo-600 hover:text-indigo-800">
                        <span x-show="hidden">Show</span><span x-show="shown" x-cloak>Hide</span>
                    </button>
                    <button type="button" x-data="copyText" data-text="{{ $integration->secret }}" x-on:click="copy" class="flex shrink-0 items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800">
                        <x-lucide-copy class="size-3.5" aria-hidden="true" /><span x-show="notCopied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 text-2xs text-slate-500">
            <span>Keep the secret key on your servers only (environment variable, plugin settings, Zapier). Never put it in a web page.</span>
            <x-confirm-dialog trigger="New Secret Key" trigger-variant="secondary" trigger-size="sm" trigger-icon="refresh-cw"
                              :action="route('settings.integrations.rotate', $integration)"
                              title="New secret key for “{{ $integration->name }}”?" confirm="Create New Key" loading="Creating…">
                The current key stops working at once. Forms using it will be refused until you paste the new key into them.
            </x-confirm-dialog>
        </div>
    </div>

    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <div>
            <h3 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Connect Your Form</h3>
            <p class="mt-0.5 text-xs text-slate-500">
                Send each submission to the address above. Field names are matched for you: name, first_name, last_name, email, phone, company,
                job_title, website, city, state, country, message… (WordPress prefixes like “your-email” are fine). Anything else is kept on the lead's timeline,
                along with utm_source, utm_medium, utm_campaign, gclid, fbclid and page_url. An email or phone is required. If the email or phone matches an existing lead,
                the submission is added to that lead instead of creating a duplicate.
            </p>
        </div>

        <x-tabs variant="pills" active="server" label="Ways to connect" :tabs="[
            'server' => ['label' => 'Next.js / Node (signed)'],
            'bearer' => ['label' => 'WordPress · Zapier · Make'],
            'html' => ['label' => 'Plain HTML form'],
        ]">
            <x-tab-panel name="server" active>
                <p class="mb-2 text-xs text-slate-600">The safest option: your server signs each post with the secret key (HMAC SHA-256 of “timestamp.body”). Posts older than 5 minutes are refused.</p>
                <pre class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-2xs leading-relaxed text-slate-100"><code>{{ $snippets['server'] }}</code></pre>
            </x-tab-panel>
            <x-tab-panel name="bearer">
                <p class="mb-2 text-xs text-slate-600">
                    For tools that can add a header but can't sign: WordPress webhook add-ons (Contact Form 7, WPForms, Gravity Forms, Elementor via a webhook plugin),
                    Zapier “Webhooks by Zapier” (POST), Make “HTTP” module. LinkedIn Lead Gen forms: Zapier/Make trigger “New lead” → this webhook.
                    Add the header <code class="text-slate-800">Authorization: Bearer &lt;secret key&gt;</code>.
                </p>
                <pre class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-2xs leading-relaxed text-slate-100"><code>{{ $snippets['bearer'] }}</code></pre>
            </x-tab-panel>
            <x-tab-panel name="html">
                <p class="mb-2 text-xs text-slate-600">
                    @if ($integration->allow_browser)
                        Browser posts are on for: {{ implode(', ', $integration->allowed_origins ?? []) }}. Posts from other sites are refused.
                    @else
                        <strong class="font-semibold text-amber-700">Browser posts are off.</strong> Turn on “Accept posts straight from a web page” and add your website under Edit first.
                    @endif
                    No secret key goes in the page. The hidden <code class="text-slate-800">_hp</code> field catches spam bots. Works with fetch() too.
                </p>
                <pre class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-2xs leading-relaxed text-slate-100"><code>{{ $snippets['html'] }}</code></pre>
            </x-tab-panel>
        </x-tabs>
    </div>

    <x-table :paginator="$submissions" title="Recent Submissions" aside="Kept for 90 days. Shows the field names received, never the values.">
        <x-slot:head>
            <tr><th>When</th><th>Result</th><th>Lead</th><th>Fields received</th><th>Problem</th></tr>
        </x-slot:head>
        @forelse ($submissions as $submission)
            <tr>
                <td class="whitespace-nowrap"><x-date :value="$submission->created_at" relative /></td>
                <td><x-badge :tone="$submission->result->tone()">{{ $submission->result->label() }}</x-badge></td>
                <td>
                    @if ($submission->lead && $submission->lead->isVisibleTo(auth()->user()))
                        <a href="{{ route('leads.show', $submission->lead) }}" class="font-semibold text-indigo-600 hover:text-indigo-800">{{ $submission->lead->reference }}</a>
                    @else
                        <span class="text-slate-500">—</span>
                    @endif
                </td>
                <td class="max-w-xs truncate text-slate-600" title="{{ implode(', ', $submission->fields ?? []) }}">{{ implode(', ', $submission->fields ?? []) ?: '—' }}</td>
                <td class="text-rose-700">{{ implode(' ', $submission->errors ?? []) }}</td>
            </tr>
        @empty
            <tr><td colspan="5">
                <x-empty-state title="Nothing received yet" icon="webhook">
                    Send a test submission from your form. It shows here within a second, with the fields we received.
                </x-empty-state>
            </td></tr>
        @endforelse
    </x-table>

    <x-modal name="integration-edit" title="Edit Website Form" :subtitle="$integration->name" :open="$errors->getBag($bag)->any()">
        <form id="integration-edit-form" method="POST" action="{{ route('settings.integrations.update', $integration) }}" novalidate>
            @csrf @method('PUT')
            @include('settings._integration-form', ['prefix' => 'int-edit'])
        </form>
        <x-slot:footer>
            <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
            <x-button form="integration-edit-form">Save Form</x-button>
        </x-slot:footer>
    </x-modal>
</x-settings-layout>
