<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Services\Leads\LeadAssigner;
use App\Services\Leads\LeadStatusChanger;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\LeadBulkRequest;

/**
 * Bulk actions from the list's selection bar: status, assign, add/remove tag, archive (admin).
 * The server rechecks every selected ID against visibility AND the policy; ids the user can't act on
 * are skipped (never revealed). Max 500 per action, all in one transaction.
 */
class LeadBulkController extends Controller
{
    public function __invoke(LeadBulkRequest $request, LeadStatusChanger $statuses, LeadAssigner $assigner): RedirectResponse
    {
        $user = $request->user();
        [$action, $value] = $request->operation();

        $leads = Lead::query()->visibleTo($user)->whereIn('id', $request->validated('ids'))->with('status')->get();

        $target = match ($action) {
            'status' => LeadStatus::query()->where('is_active', true)->find($value),
            'assign' => $value === 'none' ? null : User::query()->where('is_active', true)->find($value),
            'tag_add', 'tag_remove' => Tag::find($value),
            default => null,
        };
        if (in_array($action, ['status', 'tag_add', 'tag_remove'], true) && ! $target) {
            return back()->with('error', 'That option no longer exists. Refresh the page and try again.');
        }
        if ($action === 'assign' && $value !== 'none' && ! $target) {
            return back()->with('error', 'That user is no longer active. Choose someone else.');
        }

        $done = DB::transaction(function () use ($leads, $action, $target, $user, $statuses, $assigner) {
            $count = 0;
            foreach ($leads as $lead) {
                $allowed = match ($action) {
                    'status', 'tag_add', 'tag_remove' => Gate::forUser($user)->allows('update', $lead),
                    'assign' => Gate::forUser($user)->allows('assign', [$lead, $target]),
                    'archive' => Gate::forUser($user)->allows('archive', $lead),
                };
                if (! $allowed) {
                    continue;
                }

                match ($action) {
                    'status' => $statuses->change($lead, $target, $user, 'bulk'),
                    'assign' => $assigner->assign($lead, $target, $user, 'bulk'),
                    'tag_add' => $lead->tags()->syncWithoutDetaching([$target->id]),
                    'tag_remove' => $lead->tags()->detach($target->id),
                    'archive' => $lead->delete() && Audit::log('lead.archived', $lead, ['reference' => $lead->reference, 'via' => 'bulk']),
                };
                $count++;
            }

            Audit::log('lead.bulk_action', null, ['action' => $action, 'count' => $count, 'target' => $target?->getKey()]);

            return $count;
        });

        $skipped = count($request->validated('ids')) - $done;
        $noun = fn (int $n) => $n.' '.str('lead')->plural($n);
        $message = match ($action) {
            'status' => "{$noun($done)} moved to {$target->name}",
            'assign' => $target ? "{$noun($done)} assigned to {$target->name}" : "{$noun($done)} unassigned",
            'tag_add' => "Tag “{$target->name}” added to {$noun($done)}",
            'tag_remove' => "Tag “{$target->name}” removed from {$noun($done)}",
            'archive' => "{$noun($done)} archived",
        };

        return back()->with('status', $message.($skipped > 0 ? " · {$skipped} skipped (you can't change them)" : ''));
    }
}
