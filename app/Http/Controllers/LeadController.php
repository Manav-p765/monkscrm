<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\LeadPriority;
use App\Enums\LeadStatusType;
use App\Enums\Currency;
use App\Http\Requests\LeadListRequest;
use App\Http\Requests\LeadRequest;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Services\Leads\LeadAssigner;
use App\Services\Leads\LeadDuplicates;
use App\Services\Leads\LeadStatusChanger;
use App\Support\Audit;
use App\Support\LeadFilters;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Leads (prototype LeadListView + LeadFormModal + LeadDetailModal). Every query starts from
 * Lead::visibleTo($user); route binding already 404s leads the user can't see.
 * The panel (/leads/{reference}) and the forms (/leads/create, /leads/{reference}/edit) render the
 * list underneath, so each has its own URL and the back button works.
 */
class LeadController extends Controller
{
    public function __construct(
        private LeadStatusChanger $statuses,
        private LeadAssigner $assigner,
        private LeadDuplicates $duplicates,
    ) {}

    public function index(LeadListRequest $request): View
    {
        return $this->render($request);
    }

    public function show(LeadListRequest $request, Lead $lead): View
    {
        Gate::authorize('view', $lead);

        return $this->render($request, panel: $lead);
    }

    public function create(LeadListRequest $request): View
    {
        Gate::authorize('create', Lead::class);

        return $this->render($request, form: new Lead);
    }

    public function edit(LeadListRequest $request, Lead $lead): View
    {
        Gate::authorize('update', $lead);

        return $this->render($request, form: $lead);
    }

    public function store(LeadRequest $request): RedirectResponse
    {
        $user = $request->user();
        if ($blocked = $this->blockDuplicate($request, null)) {
            return $blocked;
        }

        $lead = DB::transaction(function () use ($request, $user) {
            $lead = new Lead($this->fields($request));
            $lead->deal_value = $request->dealValueMinor();
            $lead->created_by = $user->id;
            $lead->last_activity_at = now();
            $this->statuses->stampMilestone($lead, LeadStatus::findOrFail($lead->lead_status_id));
            $lead->save();
            $lead->tags()->sync($request->validated('tags', []));

            if ($request->filled('initial_note')) {
                $note = $lead->notes()->make(['body' => $request->validated('initial_note')]);
                $note->user_id = $user->id;
                $note->save();
            }

            $activity = $lead->activities()->make([
                'type' => ActivityType::System,
                'description' => "Lead created manually by {$user->name}",
                'occurred_at' => now(),
                'meta' => ['event' => 'created'],
            ]);
            $activity->user_id = $user->id;
            $activity->save();

            Audit::log('lead.created', $lead, ['reference' => $lead->reference]);

            return $lead;
        });

        return redirect()->route('leads.show', $lead)->with('status', 'Lead saved');
    }

    public function update(LeadRequest $request, Lead $lead): RedirectResponse
    {
        $user = $request->user();
        if ($blocked = $this->blockDuplicate($request, $lead)) {
            return $blocked;
        }

        DB::transaction(function () use ($request, $lead, $user) {
            $fields = $this->fields($request);
            $statusId = $fields['lead_status_id'];
            $assignee = array_key_exists('assigned_to', $fields) ? $fields['assigned_to'] : $lead->assigned_to;
            unset($fields['lead_status_id'], $fields['assigned_to']);

            $lead->fill($fields);
            $lead->deal_value = $request->dealValueMinor();
            $changed = array_keys($lead->getDirty());
            $lead->save();

            $tags = $request->validated('tags', []);
            $tagChanges = $lead->tags()->sync($tags);
            if (array_filter($tagChanges)) {
                $changed[] = 'tags';
            }

            if ($changed !== []) {
                Audit::log('lead.updated', $lead, ['fields' => array_values(array_unique($changed))]);
            }

            $this->statuses->change($lead, LeadStatus::findOrFail($statusId), $user, 'form');
            if ($assignee !== $lead->assigned_to) {
                $this->assigner->assign($lead, $assignee ? User::find($assignee) : null, $user, 'form');
            }
        });

        return redirect()->route('leads.show', $lead)->with('status', 'Lead saved');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        Gate::authorize('archive', $lead);

        $lead->delete();
        Audit::log('lead.archived', $lead, ['reference' => $lead->reference]);

        return redirect()->route('leads.index')->with('status', "Lead {$lead->reference} archived");
    }

    public function restore(Lead $lead): RedirectResponse
    {
        Gate::authorize('restore', $lead);

        $lead->restore();
        Audit::log('lead.restored', $lead, ['reference' => $lead->reference]);

        return redirect()->route('leads.show', $lead)->with('status', "Lead {$lead->reference} restored");
    }

    /** Panel / pipeline status change. Answers 204 / 422 (plain text) to the pipeline's fetch. */
    public function updateStatus(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);
        $data = $request->validate([
            'lead_status_id' => ['required', 'integer', Rule::exists('lead_statuses', 'id')->where('is_active', true)],
            'via' => ['nullable', Rule::in(['panel', 'kanban'])],
        ], ['lead_status_id.exists' => 'Choose an active status.']);

        $status = LeadStatus::findOrFail($data['lead_status_id']);
        $this->statuses->change($lead, $status, $request->user(), $data['via'] ?? 'panel');

        if ($request->header('X-Requested-With') === 'fetch') {
            return response()->noContent();
        }

        return back()->with('status', "Moved {$lead->displayName()} to {$status->name}");
    }

    public function updateAssignee(Request $request, Lead $lead): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)]]);
        $to = ! empty($data['assigned_to']) ? User::find($data['assigned_to']) : null;
        Gate::authorize('assign', [$lead, $to]);

        $this->assigner->assign($lead, $to, $request->user(), 'panel');

        return back()->with('status', $to ? "Assigned to {$to->name}" : 'Lead unassigned');
    }

    public function updatePriority(Request $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('update', $lead);
        $data = $request->validate(['priority' => ['nullable', Rule::enum(LeadPriority::class)]]);

        $lead->priority = $data['priority'] ?? null;
        if ($lead->isDirty('priority')) {
            $lead->save();
            Audit::log('lead.updated', $lead, ['fields' => ['priority']]);
        }

        return back()->with('status', 'Priority saved');
    }

    // ------------------------------------------------------------------------------------------

    private function render(LeadListRequest $request, ?Lead $panel = null, ?Lead $form = null): View
    {
        $user = $request->user();
        $filters = $request->filters();
        $archived = $request->boolean('archived');

        $query = Lead::query()->visibleTo($user)
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->with(['status', 'source', 'assignee:id,name', 'tags:id,name,color']);
        LeadFilters::apply($query, $filters, $user);

        $leads = $query->paginate(25)->withQueryString();

        return view('leads.index', [
            'leads' => $leads,
            'filters' => $filters,
            'archived' => $archived,
            'filtersActive' => LeadFilters::active($filters),
            'advancedOpen' => collect($filters)->only(LeadFilters::ADVANCED)->filter()->isNotEmpty(),
            'visibleTotal' => Lead::query()->visibleTo($user)->count(),
            'lookups' => $this->lookups($user, $form),
            'panel' => $panel?->load(['status', 'source', 'originalSource', 'assignee', 'creator:id,name', 'tags',
                'activities' => fn ($q) => $q->with('user:id,name')->latest('occurred_at')->limit(50)]),
            'form' => $form,
            'duplicate' => session('duplicate'),
        ]);
    }

    /** Dropdown data. Assignee choices follow LeadPolicy (sales: only themselves). */
    private function lookups(User $user, ?Lead $form): array
    {
        $canAssignOthers = Gate::forUser($user)->allows('assignTo', [Lead::class, null]);

        return [
            'statuses' => LeadStatus::query()->ordered()->get(),
            'sources' => LeadSource::query()->ordered()->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'assignees' => $canAssignOthers
                ? User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                : collect([$user->id => $user->name]),
            'canAssignOthers' => $canAssignOthers,
            'currencies' => Currency::enabled(),
            'defaultCurrency' => Currency::default(),
            'defaultStatusId' => (int) Settings::get('default_status_id'),
            'defaultSourceId' => (int) Settings::get('default_source_id'),
            'priorities' => LeadPriority::cases(),
            'openStatusIds' => LeadStatus::query()->where('type', LeadStatusType::Open)->pluck('id')->all(),
        ];
    }

    /** Fillable lead fields from the validated form (ownership fields are set separately). */
    private function fields(LeadRequest $request): array
    {
        $data = collect($request->validated())->only([
            'first_name', 'last_name', 'company', 'job_title', 'email', 'secondary_email', 'phone', 'secondary_phone',
            'website', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'description',
            'lead_status_id', 'lead_source_id', 'priority', 'currency',
        ])->all();

        if ($request->has('assigned_to')) {
            $data['assigned_to'] = $request->validated('assigned_to') ?: null;
        } elseif (! $request->route('lead')) {
            $data['assigned_to'] = $request->user()->id;
        }

        return $data;
    }

    /**
     * Duplicates on normalized email/phone: sales are always blocked; admins/managers may save anyway
     * (ticking "Save anyway") while allow_duplicate_override is on, and the override is audited.
     * Details are only shown for a matching lead the user can see.
     */
    private function blockDuplicate(LeadRequest $request, ?Lead $lead): ?RedirectResponse
    {
        $matches = $this->duplicates->find($request->validated('email'), $request->validated('phone'), $lead?->id);
        if ($matches->isEmpty()) {
            return null;
        }

        $user = $request->user();
        $canOverride = $user->can('overrideDuplicate', Lead::class);

        if ($canOverride && $request->boolean('confirm_duplicate')) {
            Audit::log('lead.duplicate_override', $lead, ['matches' => $matches->pluck('reference')->all()]);

            return null;
        }

        $visible = $this->duplicates->visibleMatch($matches, $user);
        $on = $this->duplicates->matchedOn($matches, $request->validated('email'), $request->validated('phone'));

        return back()->withInput()
            ->withErrors([$on === 'phone' ? 'phone' : 'email' => $canOverride
                ? "A lead with this {$on} already exists. Tick “Save anyway” to create it regardless."
                : "A lead with this {$on} already exists. Open the existing lead instead, or ask a manager."])
            ->with('duplicate', [
                'on' => $on,
                'name' => $visible?->displayName(),
                'reference' => $visible?->reference,
                'company' => $visible?->company,
                'can_override' => $canOverride,
            ]);
    }
}
