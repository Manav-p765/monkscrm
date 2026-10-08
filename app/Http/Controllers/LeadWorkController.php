<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Http\Requests\ActivityRequest;
use App\Http\Requests\FollowUpRequest;
use App\Http\Requests\NoteRequest;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\FollowUps;
use App\Support\Audit;
use App\Support\Dates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Work on a lead from its panel: notes, logged activities and follow-ups. Leads, notes and
 * follow-ups the user can't see never resolve (404); LeadPolicy decides what they may do.
 */
class LeadWorkController extends Controller
{
    public function __construct(private ActivityLogger $activities, private FollowUps $followUps) {}

    // ---- Notes -------------------------------------------------------------------------------

    public function storeNote(NoteRequest $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('logActivity', $lead);

        DB::transaction(function () use ($request, $lead) {
            $note = $lead->notes()->make(['body' => $request->validated('body')]);
            $note->user_id = $request->user()->id;
            $note->save();
            $this->activities->touch($lead, now(), false);
        });

        return $this->backToLead($lead, 'notes')->with('status', 'Note added');
    }

    public function updateNote(NoteRequest $request, LeadNote $note): RedirectResponse
    {
        if (! Gate::allows('updateNote', [Lead::class, $note])) {
            return $this->backToLead($note->lead, 'notes')->with('error', 'Notes can only be edited by their author within '.LeadNote::EDIT_WINDOW_MINUTES.' minutes.');
        }

        $note->update(['body' => $request->validated('body')]);

        return $this->backToLead($note->lead, 'notes')->with('status', 'Note saved');
    }

    public function destroyNote(LeadNote $note): RedirectResponse
    {
        Gate::authorize('deleteNote', [Lead::class, $note]);

        $lead = $note->lead;
        DB::transaction(function () use ($note, $lead) {
            $note->delete();
            $this->activities->system($lead, auth()->user(), 'note_deleted', 'A note was deleted', ['note_id' => $note->id]);
            Audit::log('lead.note_deleted', $lead, ['note_id' => $note->id]);
        });

        return $this->backToLead($lead, 'notes')->with('status', 'Note deleted');
    }

    // ---- Activities --------------------------------------------------------------------------

    public function storeActivity(ActivityRequest $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('logActivity', $lead);
        $user = $request->user();
        $data = $request->validated();

        if ($request->boolean('schedule_follow_up')) {
            Gate::authorize('scheduleFollowUp', $lead);
        }

        DB::transaction(function () use ($data, $lead, $user, $request) {
            $type = ActivityType::from($data['type']);
            $at = ! empty($data['occurred_at']) ? Dates::fromInput($data['occurred_at']) : now();
            $this->activities->log($lead, $user, $type, $data['description'], $data['outcome'] ?? null, $at);

            if ($request->boolean('schedule_follow_up')) {
                $this->followUps->schedule($lead, $user, $user, FollowUpType::from($data['follow_up_type']),
                    Dates::fromInput($data['follow_up_due_at']), null);
            }
        });

        return $this->backToLead($lead, 'timeline')->with('status', ucfirst($data['type'] === 'sms' ? 'SMS' : $data['type']).' logged');
    }

    // ---- Follow-ups --------------------------------------------------------------------------

    public function storeFollowUp(FollowUpRequest $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('scheduleFollowUp', $lead);
        $assignee = User::findOrFail($request->validated('assigned_to'));

        if (! Gate::allows('assignFollowUpTo', [Lead::class, $lead, $assignee])) {
            throw ValidationException::withMessages(['assigned_to' => \App\Support\Permissions::allows($request->user(), 'assign-leads')
                ? "{$assignee->name} can't see this lead. Choose someone who can."
                : 'You can only schedule follow-ups for yourself.'])->errorBag('followUp');
        }

        $this->followUps->schedule($lead, $request->user(), $assignee, FollowUpType::from($request->validated('type')),
            Dates::fromInput($request->validated('due_at')), $request->validated('notes'));

        return $this->backToLead($lead, 'followups')->with('status', 'Follow-up scheduled');
    }

    public function updateFollowUp(FollowUpRequest $request, FollowUp $followUp): RedirectResponse
    {
        Gate::authorize('manageFollowUp', [Lead::class, $followUp]);
        $user = $request->user();
        $action = $request->validated('action');
        $status = $followUp->status;

        $allowed = match ($action) {
            'complete', 'cancel', 'reschedule' => $status === FollowUpStatus::Pending,
            'reopen' => $status !== FollowUpStatus::Pending,
        };
        if (! $allowed) {
            return back()->with('error', 'That follow-up has already changed. Refresh the page and try again.');
        }

        match ($action) {
            'complete' => $this->followUps->complete($followUp, $user, trim((string) $request->validated('outcome')) ?: null),
            'cancel' => $this->followUps->cancel($followUp, $user),
            'reschedule' => $this->followUps->reschedule($followUp, $user, Dates::fromInput($request->validated('due_at'))),
            'reopen' => $this->followUps->reopen($followUp, $user),
        };

        $message = ['complete' => 'Follow-up marked as done', 'cancel' => 'Follow-up cancelled',
            'reschedule' => 'Follow-up rescheduled', 'reopen' => 'Follow-up reopened'][$action];

        return back()->with('status', $message);
    }

    private function backToLead(Lead $lead, string $tab): RedirectResponse
    {
        return redirect()->to(route('leads.show', $lead).'?tab='.$tab);
    }
}
