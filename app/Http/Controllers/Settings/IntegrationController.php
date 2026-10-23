<?php

namespace App\Http\Controllers\Settings;

use App\Enums\IntegrationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\IntegrationRequest;
use App\Models\Integration;
use App\Models\LeadSource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Settings → Integrations (admin only, route middleware can:manage-settings): website forms that
 * send leads in through POST /intake/{key}. Never deleted, only turned off.
 */
class IntegrationController extends Controller
{
    public function index(): View
    {
        return view('settings.integrations', [
            'integrations' => Integration::query()->with(['source:id,name', 'assignee:id,name'])->withCount('leads')->orderBy('name')->get(),
            ...$this->options(),
        ]);
    }

    public function show(Integration $integration): View
    {
        Gate::authorize('manage-settings');

        return view('settings.integration', [
            'integration' => $integration->load(['source:id,name', 'assignee:id,name']),
            'submissions' => $integration->submissions()->with('lead')->latest('created_at')->latest('id')->paginate(25),
            ...$this->options(),
        ]);
    }

    public function store(IntegrationRequest $request): RedirectResponse
    {
        $integration = new Integration($request->values());
        $integration->type = IntegrationType::Website;
        $integration->public_key = Integration::generateKey();
        $integration->secret = Integration::generateSecret();
        $integration->created_by = $request->user()->id;
        $integration->save();

        Audit::log('integration.created', $integration, ['name' => $integration->name, 'type' => $integration->type->value]);

        return redirect()->route('settings.integrations.show', $integration)
            ->with('status', "“{$integration->name}” added. Connect your form with the details below.");
    }

    public function update(IntegrationRequest $request, Integration $integration): RedirectResponse
    {
        $integration->fill($request->values());
        $changes = collect($integration->getDirty())
            ->map(fn ($to, $field) => ['from' => $integration->getOriginal($field), 'to' => $to])
            ->all();
        $integration->save();

        if ($changes !== []) {
            Audit::log('integration.updated', $integration, ['changes' => $changes]);
        }

        return back()->with('status', "“{$integration->name}” saved");
    }

    /** New secret key; the old one stops working at once. */
    public function rotate(Integration $integration): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $integration->secret = Integration::generateSecret();
        $integration->save();
        Audit::log('integration.key_rotated', $integration, ['name' => $integration->name]);

        return redirect()->route('settings.integrations.show', $integration)
            ->with('status', 'New secret key created. Update it on your website; the old key no longer works.');
    }

    /** @return array{sources: \Illuminate\Support\Collection, users: \Illuminate\Support\Collection} */
    private function options(): array
    {
        return [
            'sources' => LeadSource::query()->active()->ordered()->pluck('name', 'id'),
            'users' => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ];
    }
}
