<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderRequest;
use App\Http\Requests\SourceRequest;
use App\Models\LeadSource;
use App\Support\Audit;
use App\Support\Reorder;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Lead sources: add, rename, reorder, turn on/off and choose the default. Never deleted
 * (a lead's original source must keep pointing at it).
 */
class LeadSourceController extends Controller
{
    public function store(SourceRequest $request): RedirectResponse
    {
        $source = LeadSource::create([
            ...$request->validated(),
            'sort_order' => (int) LeadSource::query()->max('sort_order') + 10,
        ]);
        Audit::log('source.created', $source, ['name' => $source->name]);

        return redirect()->route('settings.sources')->with('status', "Source “{$source->name}” added");
    }

    public function update(SourceRequest $request, LeadSource $source): RedirectResponse
    {
        $source->fill($request->validated());
        $changes = collect($source->getDirty())
            ->map(fn ($to, $field) => ['from' => $source->getOriginal($field), 'to' => $to])
            ->all();
        $source->save();

        if ($changes !== []) {
            Audit::log('source.updated', $source, ['changes' => $changes]);
        }

        return redirect()->route('settings.sources')->with('status', "Source “{$source->name}” saved");
    }

    public function makeDefault(LeadSource $source): RedirectResponse
    {
        Gate::authorize('manage-settings');

        if (! $source->is_active) {
            return back()->with('error', 'Turn this source on before making it the default.');
        }

        Settings::update(['default_source_id' => $source->id]);

        return redirect()->route('settings.sources')->with('status', "New leads will default to “{$source->name}”");
    }

    public function reorder(ReorderRequest $request, ?LeadSource $source = null): RedirectResponse
    {
        if ($source && $request->filled('direction')) {
            Reorder::move($source, $request->validated('direction'));
        } elseif (! Reorder::apply(LeadSource::class, $request->validated('ids', []))) {
            return back()->with('error', 'The list changed while you were reordering it. Please try again.');
        }

        Audit::log('sources.reordered', meta: ['order' => LeadSource::query()->ordered()->pluck('id')->all()]);

        return redirect()->route('settings.sources')->with('status', 'Order saved');
    }
}
