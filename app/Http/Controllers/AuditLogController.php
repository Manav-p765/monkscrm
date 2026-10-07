<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogFilterRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Admin-only, read-only view of the audit log (route middleware can:view-audit-log).
 * There is no edit or delete anywhere: the table is append-only.
 */
class AuditLogController extends Controller
{
    public function index(AuditLogFilterRequest $request): View
    {
        return $this->render($request);
    }

    /** Same list, with one entry's details open in the slide-over (its own URL). */
    public function show(AuditLogFilterRequest $request, AuditLog $auditLog): View
    {
        return $this->render($request, $auditLog->load('user', 'subject'));
    }

    private function render(AuditLogFilterRequest $request, ?AuditLog $selected = null): View
    {
        $filters = $request->validated();

        return view('audit-log.index', [
            'entries' => $this->entries($filters),
            'filters' => $filters,
            'filtersActive' => array_filter($filters) !== [],
            'users' => User::query()->orderBy('name')->pluck('name', 'id'),
            'actions' => config('crm.audit_actions'),
            'selected' => $selected,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function entries(array $filters): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('user:id,name,email')
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            // The dates people pick are IST days; compare against UTC timestamps.
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Dates::fromInput($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', Dates::fromInput($to)->addDay()))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();
    }
}
