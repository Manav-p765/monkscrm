<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Currency;
use App\Enums\LeadMilestone;
use App\Enums\LeadStatusType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\GeneralSettingsRequest;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Settings pages (admin only, route middleware can:manage-settings), matching the prototype's
 * SettingsView sub-tabs: statuses, sources, tags, security & rules, plus the permissions table.
 */
class SettingsController extends Controller
{
    public function statuses(): View
    {
        return view('settings.statuses', [
            'statuses' => LeadStatus::query()->ordered()->withCount('leads')->get(),
            'defaultStatusId' => (int) Settings::get('default_status_id'),
            'palette' => collect(config('crm.palette'))->map(fn ($c) => $c['label']),
            'types' => collect(LeadStatusType::cases())->mapWithKeys(fn ($t) => [$t->value => ucfirst($t->value)]),
            'milestones' => collect(LeadMilestone::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]),
        ]);
    }

    public function sources(): View
    {
        return view('settings.sources', [
            'sources' => LeadSource::query()->ordered()->withCount('leads')->get(),
            'defaultSourceId' => (int) Settings::get('default_source_id'),
        ]);
    }

    public function tags(): View
    {
        return view('settings.tags', [
            'tags' => Tag::query()->orderBy('name')->withCount('leads')->get(),
            'palette' => collect(config('crm.palette'))->map(fn ($c) => $c['label']),
        ]);
    }

    public function general(): View
    {
        return view('settings.general', [
            'settings' => Settings::all(),
            'currencies' => Currency::cases(),
            'enabled' => array_map(fn (Currency $c) => $c->value, Currency::enabled()),
            'defaultCurrency' => Currency::default()->value,
        ]);
    }

    public function updateGeneral(GeneralSettingsRequest $request): RedirectResponse
    {
        $changed = Settings::update($request->settings());

        return redirect()->route('settings.general')
            ->with('status', $changed === [] ? 'No changes to save' : 'Settings saved');
    }

    public function permissions(): View
    {
        return view('settings.permissions', [
            'matrixPermissions' => Permissions::all(),
            'matrixRoles' => Role::cases(),
            'matrixSettings' => Settings::all(),
        ]);
    }
}
