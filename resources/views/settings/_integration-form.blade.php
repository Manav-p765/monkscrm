{{-- Fields for adding / editing a website form integration. Needs $sources, $users, $bag, $prefix; $integration optional. --}}
@php($integration ??= null)
<div class="space-y-3.5">
    <x-input name="name" label="Name" :bag="$bag" :id="$prefix.'-name'" :value="$integration?->name" placeholder="e.g. Contact form – hybridmonks.com" required />
    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
        <x-select name="lead_source_id" label="Source for new leads" :bag="$bag" :id="$prefix.'-source'" :options="$sources"
                  :value="$integration?->lead_source_id" placeholder="Default source" />
        <x-select name="assign_to" label="Assigned to" :bag="$bag" :id="$prefix.'-assign'" :options="$users"
                  :value="$integration?->assign_to" placeholder="Leave unassigned" />
    </div>
    <input type="hidden" name="allow_browser" value="0">
    <x-checkbox name="allow_browser" label="Accept posts straight from a web page" :bag="$bag" :id="$prefix.'-browser'"
                :checked="$integration?->allow_browser"
                hint="For plain HTML forms that post to the CRM. Not needed when your website's server, WordPress plugin, Zapier or Make sends the lead with the secret key." />
    <x-textarea name="allowed_origins" label="Allowed websites" :bag="$bag" :id="$prefix.'-origins'" rows="2"
                :value="implode(PHP_EOL, $integration?->allowed_origins ?? [])"
                hint="One per line, e.g. https://hybridmonks.com. Browser posts from any other site are refused." />
    <x-input name="redirect_url" label="Thank-you page" :bag="$bag" :id="$prefix.'-redirect'" :value="$integration?->redirect_url"
             placeholder="https://example.com/thank-you" hint="Where visitors go after a plain HTML form posts. Optional." />
    @if ($integration)
        <input type="hidden" name="is_active" value="0">
        <x-checkbox name="is_active" label="Active" :bag="$bag" :id="$prefix.'-active'" :checked="$integration->is_active"
                    hint="When off, posts to this form are refused and nothing is created." />
    @endif
</div>
