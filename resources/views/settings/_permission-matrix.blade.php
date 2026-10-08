{{-- Read-only role matrix (prototype "Role & Access Control Matrix"), rendered from config('crm.permissions'). --}}
<div class="overflow-x-auto rounded-xl border border-slate-200">
    <table class="w-full divide-y divide-slate-200 text-left text-xs">
        <thead class="bg-slate-50 font-semibold text-slate-600">
            <tr>
                <th class="px-4 py-2.5">Feature / Permission</th>
                @foreach ($matrixRoles as $role)
                    <th class="px-4 py-2.5 text-center">{{ $role->label() }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($matrixPermissions as $permission)
                <tr class="hover:bg-slate-50/50" data-permission="{{ $permission['key'] }}">
                    <td class="px-4 py-2.5 font-medium text-slate-800">{{ $permission['label'] }}</td>
                    @foreach ($matrixRoles as $role)
                        @php
                            $allowed = in_array($role->value, $permission['roles'], true);
                            $configurable = ! $allowed && $role->value === 'sales' && isset($permission['setting']);
                        @endphp
                        <td class="px-4 py-2.5 text-center" data-role="{{ $role->value }}" data-allowed="{{ $allowed ? 'yes' : ($configurable ? 'setting' : 'no') }}">
                            @if ($allowed)
                                <x-lucide-check class="mx-auto size-3.5 text-emerald-600" aria-hidden="true" /><span class="sr-only">Yes</span>
                            @elseif ($configurable)
                                <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-3xs font-semibold text-indigo-700">
                                    Configurable · {{ ($matrixSettings[$permission['setting']] ?? false) ? 'On' : 'Off' }}
                                </span>
                            @else
                                <x-lucide-x class="mx-auto size-3.5 text-slate-300" aria-hidden="true" /><span class="sr-only">No</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
