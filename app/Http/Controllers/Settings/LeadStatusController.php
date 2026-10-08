<?php

namespace App\Http\Controllers\Settings;

use App\Enums\LeadStatusType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderRequest;
use App\Http\Requests\StatusRequest;
use App\Models\LeadStatus;
use App\Support\Audit;
use App\Support\Reorder;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Pipeline statuses. Nothing is ever deleted: disabled statuses still show on existing leads but
 * are hidden from pickers. Exactly one active Open status is the default (setting default_status_id).
 */
class LeadStatusController extends Controller
{
    public function store(StatusRequest $request): RedirectResponse
    {
        $status = LeadStatus::create([
            ...$request->statusData(),
            'sort_order' => (int) LeadStatus::query()->max('sort_order') + 10,
        ]);
        Audit::log('status.created', $status, ['name' => $status->name]);

        return redirect()->route('settings.statuses')->with('status', "Status “{$status->name}” added");
    }

    public function update(StatusRequest $request, LeadStatus $status): RedirectResponse
    {
        $status->fill($request->statusData());
        $changes = $this->changes($status);
        $status->save();

        if ($changes !== []) {
            Audit::log('status.updated', $status, ['changes' => $changes]);
        }

        return redirect()->route('settings.statuses')->with('status', "Status “{$status->name}” saved");
    }

    public function makeDefault(LeadStatus $status): RedirectResponse
    {
        Gate::authorize('manage-settings');

        if (! $status->is_active || $status->type !== LeadStatusType::Open) {
            return back()->with('error', 'Only an active Open status can be the default for new leads.');
        }

        Settings::update(['default_status_id' => $status->id]);

        return redirect()->route('settings.statuses')->with('status', "New leads will start as “{$status->name}”");
    }

    public function reorder(ReorderRequest $request, ?LeadStatus $status = null): RedirectResponse
    {
        if ($status && $request->filled('direction')) {
            Reorder::move($status, $request->validated('direction'));
        } elseif (! Reorder::apply(LeadStatus::class, $request->validated('ids', []))) {
            return back()->with('error', 'The list changed while you were reordering it. Please try again.');
        }

        Audit::log('statuses.reordered', meta: ['order' => LeadStatus::query()->ordered()->pluck('id')->all()]);

        return redirect()->route('settings.statuses')->with('status', 'Order saved');
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(LeadStatus $status): array
    {
        $changes = [];
        foreach ($status->getDirty() as $field => $value) {
            $from = $status->getOriginal($field);
            $changes[$field] = [
                'from' => $from instanceof \BackedEnum ? $from->value : $from,
                'to' => $status->{$field} instanceof \BackedEnum ? $status->{$field}->value : $status->{$field},
            ];
        }

        return $changes;
    }
}
