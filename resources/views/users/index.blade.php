@php
    $roleTone = ['admin' => 'red', 'manager' => 'indigo', 'sales' => 'neutral'];
@endphp

<x-layouts.app :title="$canManage ? 'Users & Teams' : 'Team Directory'"
                :subtitle="$canManage ? 'Invite staff, set roles and teams, and manage access' : 'View-only list of your colleagues'"
                :count="$users->total().' members'">
    @if ($canManage)
        <x-slot:actions>
            <x-button type="button" icon="plus" x-data="modalTrigger" data-modal="invite" x-on:click="open">Invite Team Member</x-button>
        </x-slot:actions>
    @endif

    <x-filter-bar :action="route('users.index')" :active="$filtersActive">
        <x-input name="q" label="Search users" size="filter" icon="search" :value="$filters['q'] ?? null" placeholder="Search by name, email or job title…" />
        <x-select name="role" label="Role" size="filter" :options="$roles" :value="$filters['role'] ?? null" placeholder="All roles" />
        <x-select name="team" label="Team" size="filter" :options="$teams->pluck('name', 'id')" :value="$filters['team'] ?? null" placeholder="All teams" />
        <x-select name="status" label="Status" size="filter" :options="['active' => 'Active', 'invited' => 'Invited', 'deactivated' => 'Deactivated']" :value="$filters['status'] ?? null" placeholder="Any status" />
    </x-filter-bar>

    <x-table :paginator="$users" :title="'Internal Staff Directory ('.$users->total().' members)'"
             :aside="$canManage ? 'Admin mode: invite, edit, reset access and deactivate' : 'View-only directory'">
        <x-slot:head>
            <tr>
                <th>Member Name &amp; Title</th>
                <th>System Role</th>
                <th>Department / Team</th>
                @if ($canManage)
                    <th>Login Email</th>
                @endif
                <th>Status</th>
                @if ($canManage)
                    <th>Last Active</th>
                    <th class="text-right">Actions</th>
                @endif
            </tr>
        </x-slot:head>

        @forelse ($users as $user)
            <tr>
                <td>
                    <div class="flex items-center gap-2.5">
                        <x-avatar :user="$user" size="md" />
                        <div>
                            <div class="font-bold text-slate-900">{{ $user->name }}</div>
                            <div class="text-2xs text-slate-500">{{ $user->job_title ?: 'Team Member' }}</div>
                        </div>
                    </div>
                </td>
                <td><x-badge :tone="$roleTone[$user->role->value]" class="tracking-wider uppercase">{{ $user->role->value }}</x-badge></td>
                <td class="font-medium text-slate-700">{{ $user->team?->name ?? '—' }}</td>
                @if ($canManage)
                    <td class="text-slate-600">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-1 font-mono text-2xs">
                                <x-lucide-mail class="size-3 text-slate-400" aria-hidden="true" />
                                <span>{{ $user->email }}</span>
                            </div>
                            @if ($user->phone)
                                <div class="flex items-center gap-1 text-2xs text-slate-500">
                                    <x-lucide-phone class="size-3 text-slate-400" aria-hidden="true" />
                                    <span>{{ $user->phone }}</span>
                                </div>
                            @endif
                        </div>
                    </td>
                @endif
                <td>
                    @if (! $user->is_active)
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-3xs font-bold text-slate-600">Deactivated</span>
                    @elseif ($user->isInvitePending())
                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-3xs font-bold text-amber-700">Invited</span>
                    @else
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-3xs font-bold text-emerald-700">Active</span>
                    @endif
                </td>
                @if ($canManage)
                    <td class="text-2xs text-slate-500"><x-date :value="$user->last_login_at" relative empty="Never" /></td>
                    <td class="text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            @can('sendPasswordReset', $user)
                                <form method="POST" action="{{ route('users.password-reset', $user) }}">
                                    @csrf
                                    <button type="submit" title="Send password reset link" class="rounded p-1.5 text-slate-500 transition hover:bg-indigo-50 hover:text-indigo-600">
                                        <span class="sr-only">Send password reset link to {{ $user->name }}</span>
                                        <x-lucide-key-round class="size-3.5" aria-hidden="true" />
                                    </button>
                                </form>
                            @endcan
                            <a href="{{ route('users.edit', [$user, ...request()->query()]) }}" title="Edit role & team" class="rounded p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-indigo-600">
                                <span class="sr-only">Edit {{ $user->name }}</span>
                                <x-lucide-edit class="size-3.5" aria-hidden="true" />
                            </a>
                            @can('deactivate', $user)
                                <a href="{{ route('users.deactivate.confirm', [$user, ...request()->query()]) }}" title="Deactivate account" class="rounded p-1.5 text-slate-500 transition hover:bg-rose-50 hover:text-rose-600">
                                    <span class="sr-only">Deactivate {{ $user->name }}</span>
                                    <x-lucide-user-x class="size-3.5" aria-hidden="true" />
                                </a>
                            @endcan
                            @can('reactivate', $user)
                                <form method="POST" action="{{ route('users.reactivate', $user) }}">
                                    @csrf
                                    <button type="submit" title="Reactivate account" class="rounded p-1.5 text-slate-500 transition hover:bg-emerald-50 hover:text-emerald-600">
                                        <span class="sr-only">Reactivate {{ $user->name }}</span>
                                        <x-lucide-user-check class="size-3.5" aria-hidden="true" />
                                    </button>
                                </form>
                            @endcan
                            <x-dropdown width="w-56">
                                <x-slot:trigger>
                                    <button type="button" title="More actions" class="rounded p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
                                        <span class="sr-only">More actions for {{ $user->name }}</span>
                                        <x-lucide-more-vertical class="size-3.5" aria-hidden="true" />
                                    </button>
                                </x-slot:trigger>
                                @can('resendInvite', $user)
                                    <form method="POST" action="{{ route('users.resend-invite', $user) }}">@csrf<button type="submit"><x-lucide-mail class="size-3.5 text-slate-400" aria-hidden="true" /> Resend invite</button></form>
                                @endcan
                                @can('disableTwoFactor', $user)
                                    <form method="POST" action="{{ route('users.two-factor.disable', $user) }}">@csrf @method('DELETE')<button type="submit"><x-lucide-shield-off class="size-3.5 text-slate-400" aria-hidden="true" /> Turn off two-step sign-in</button></form>
                                @endcan
                                <form method="POST" action="{{ route('users.sessions.revoke', $user) }}">@csrf @method('DELETE')<button type="submit"><x-lucide-log-out class="size-3.5 text-slate-400" aria-hidden="true" /> Sign out everywhere</button></form>
                            </x-dropdown>
                        </div>
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-empty-state :title="$filtersActive ? 'No users match these filters' : 'No users yet'" icon="users-round">
                        {{ $filtersActive ? 'Try a different search or clear the filters.' : 'Invite your first team member.' }}
                        @if ($filtersActive)
                            <x-slot:actions><x-button variant="text" :href="route('users.index')">Clear filters</x-button></x-slot:actions>
                        @endif
                    </x-empty-state>
                </td>
            </tr>
        @endforelse
    </x-table>

    @if ($canManage)
        {{-- Role & permission matrix (prototype), generated from the real permission rules --}}
        <section aria-labelledby="matrix-heading" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
            <div>
                <h2 id="matrix-heading" class="text-sm font-bold text-slate-900">Role &amp; Access Control Matrix</h2>
                <p class="mt-0.5 text-xs text-slate-500">What Admin, Manager and Sales can do. Change "Configurable" items in Settings → Security &amp; Rules.</p>
            </div>
            @include('settings._permission-matrix')
        </section>

        {{-- Teams --}}
        <section aria-labelledby="teams-heading" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
            <div>
                <h2 id="teams-heading" class="text-sm font-bold text-slate-900">Teams</h2>
                <p class="mt-0.5 text-xs text-slate-500">For display and filtering only. Teams don't change who can see which leads.</p>
            </div>

            @if ($teams->isEmpty())
                <p class="text-xs text-slate-500">No teams yet. Add one below.</p>
            @else
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($teams as $team)
                        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div>
                                <span class="text-xs font-semibold text-slate-800">{{ $team->name }}</span>
                                <span class="block text-3xs text-slate-500">{{ $team->users_count }} {{ \Illuminate\Support\Str::plural('member', $team->users_count) }}</span>
                            </div>
                            <x-button type="button" variant="text" size="sm" x-data="modalTrigger" data-modal="team-{{ $team->id }}" x-on:click="open">Rename</x-button>
                        </div>
                        <x-modal name="team-{{ $team->id }}" title="Rename Team" :subtitle="$team->name" size="sm" :open="$errors->getBag('team'.$team->id)->any()">
                            <form id="team-form-{{ $team->id }}" method="POST" action="{{ route('teams.update', $team) }}" novalidate>
                                @csrf @method('PUT')
                                <x-input name="name" label="Team Name" :value="$team->name" :bag="'team'.$team->id" :id="'team-name-'.$team->id" required />
                            </form>
                            <x-slot:footer>
                                <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                                <x-button form="team-form-{{ $team->id }}">Save Team Name</x-button>
                            </x-slot:footer>
                        </x-modal>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('teams.store') }}" class="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-start" novalidate>
                @csrf
                <div class="flex-1">
                    <x-input name="name" label="New Team Name" bag="team" id="new-team-name" placeholder="e.g. Enterprise Sales" required />
                </div>
                <x-button icon="plus" class="sm:mt-5">Add Team</x-button>
            </form>
        </section>

        {{-- Invite --}}
        <x-modal name="invite" title="Invite & Create Team Account" subtitle="They'll get an email with a link to set their own password" size="lg" :open="$errors->invite->any()">
            <form id="invite-form" method="POST" action="{{ route('users.store') }}" class="space-y-3.5" novalidate>
                @csrf
                <x-input name="name" label="Full Name" bag="invite" id="invite-name" placeholder="e.g. Jordan Hayes" required />
                <x-input name="email" type="email" label="Login Email Address" bag="invite" id="invite-email" placeholder="jordan.hayes@hybridmonks.com" required />
                <div class="flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-2xs text-slate-600">
                    <x-lucide-mail class="mt-0.5 size-3.5 shrink-0 text-indigo-600" aria-hidden="true" />
                    <span>No password needed. We'll email a link they use to choose their own password. It works once and expires in 48 hours. You'll also see the link once, so you can share it privately if email isn't working yet.</span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-select name="role" label="System Role" bag="invite" id="invite-role" :options="$roles" value="sales" required />
                    <x-select name="team_id" label="Team / Dept" bag="invite" id="invite-team" :options="$teams->pluck('name', 'id')" placeholder="No team" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-input name="job_title" label="Job Title" bag="invite" id="invite-title" placeholder="e.g. Account Executive" />
                    <x-input name="phone" type="tel" label="Direct Phone" bag="invite" id="invite-phone" placeholder="+91 98765 43210" />
                </div>
            </form>
            <x-slot:footer>
                <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                <x-button form="invite-form" loading="Sending invite…">Create &amp; Send Invite</x-button>
            </x-slot:footer>
        </x-modal>

        {{-- Edit --}}
        @if ($editing)
            <x-modal name="edit-user" title="Edit Member: {{ $editing->name }}" open :close-href="route('users.index', request()->query())">
                <form id="edit-user-form" method="POST" action="{{ route('users.update', $editing) }}" class="space-y-3.5" novalidate>
                    @csrf @method('PUT')
                    <x-input name="name" label="Full Name" bag="editUser" id="edit-name" :value="$editing->name" required />
                    <x-input name="email" type="email" label="Login Email Address" bag="editUser" id="edit-email" :value="$editing->email" required />
                    <div class="grid grid-cols-2 gap-3">
                        <x-select name="role" label="System Role" bag="editUser" id="edit-role" :options="$roles" :value="$editing->role" required />
                        <x-select name="team_id" label="Team / Department" bag="editUser" id="edit-team" :options="$teams->pluck('name', 'id')" :value="$editing->team_id" placeholder="No team" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-input name="job_title" label="Job Title" bag="editUser" id="edit-title" :value="$editing->job_title" />
                        <x-input name="phone" type="tel" label="Direct Phone" bag="editUser" id="edit-phone" :value="$editing->phone" />
                    </div>
                </form>
                <x-slot:footer>
                    <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                    <x-button form="edit-user-form">Save Changes</x-button>
                </x-slot:footer>
            </x-modal>
        @endif

        {{-- Deactivate (destructive: names the user, offers reassignment) --}}
        @if ($deactivating)
            <x-modal name="deactivate-user" title="Deactivate {{ $deactivating->name }}?" open :close-href="route('users.index', request()->query())">
                <form id="deactivate-form" method="POST" action="{{ route('users.deactivate', $deactivating) }}" class="space-y-3.5" novalidate>
                    @csrf
                    <p class="text-xs leading-relaxed text-slate-600">
                        {{ $deactivating->name }} will be signed out everywhere and won't be able to sign in. Their account and history are kept, and you can reactivate them later.
                    </p>
                    <p class="text-xs leading-relaxed text-slate-600">
                        They have <strong class="text-slate-900">{{ $deactivateCounts['leads'] }} open {{ \Illuminate\Support\Str::plural('lead', $deactivateCounts['leads']) }}</strong>
                        and <strong class="text-slate-900">{{ $deactivateCounts['follow_ups'] }} pending {{ \Illuminate\Support\Str::plural('follow-up', $deactivateCounts['follow_ups']) }}</strong>.
                    </p>
                    <x-select name="reassign_to" label="Move them to" bag="deactivate" :options="$reassignOptions" placeholder="Leave unassigned" />
                </form>
                <x-slot:footer>
                    <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                    <x-button form="deactivate-form" variant="danger" loading="Deactivating…">Deactivate {{ $deactivating->name }}</x-button>
                </x-slot:footer>
            </x-modal>
        @endif

        {{-- Credentials card: the link is shown once --}}
        @if ($credential)
            <x-modal name="credential" :title="$credential['kind'] === 'invite' ? 'Invite Link Ready to Share' : 'Password Reset Link Ready'"
                     :subtitle="'Provide this to '.$credential['name'].' privately'" open>
                <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div>
                        <span class="mb-0.5 block text-3xs font-bold text-slate-500 uppercase">Login Email</span>
                        <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <span class="truncate font-mono text-xs font-semibold text-slate-800">{{ $credential['email'] }}</span>
                            <button type="button" x-data="copyText" data-text="{{ $credential['email'] }}" x-on:click="copy" class="ml-2 flex shrink-0 items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800">
                                <x-lucide-copy class="size-3" aria-hidden="true" /><span x-show="notCopied">Copy</span><span x-cloak x-show="copied">Copied</span>
                            </button>
                        </div>
                    </div>
                    <div>
                        <span class="mb-0.5 block text-3xs font-bold text-slate-500 uppercase">{{ $credential['kind'] === 'invite' ? 'Set-Password Link' : 'Reset Link' }}</span>
                        <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <span class="truncate font-mono text-2xs text-slate-800">{{ $credential['url'] }}</span>
                            <button type="button" x-data="copyText" data-text="{{ $credential['url'] }}" x-on:click="copy" class="ml-2 flex shrink-0 items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800">
                                <x-lucide-copy class="size-3" aria-hidden="true" /><span x-show="notCopied">Copy</span><span x-cloak x-show="copied">Copied</span>
                            </button>
                        </div>
                    </div>
                    <p class="text-2xs text-slate-500">
                        {{ $credential['kind'] === 'invite' ? 'Works once and expires in 48 hours.' : 'Works once and expires in 30 minutes.' }}
                        It won't be shown again.@if ($credential['emailed'] ?? false) We've also emailed it to them.@endif
                    </p>
                </div>
                @unless ($credential['emailed'] ?? false)
                    <div role="alert" class="mt-3 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        <x-lucide-alert-triangle class="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden="true" />
                        <p><span class="font-bold">We couldn't send the email.</span> Copy the link above and share it privately.</p>
                    </div>
                @endunless
                <x-slot:footer>
                    <x-button type="button" variant="ghost" x-on:click="close">Done</x-button>
                </x-slot:footer>
            </x-modal>
        @endif
    @endif
</x-layouts.app>
