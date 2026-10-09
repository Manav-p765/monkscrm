<?php

namespace App\Http\Controllers;

use App\Enums\FollowUpStatus;
use App\Support\LeadStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard (prototype DashboardView). Everything is scoped by lead visibility and built from a
 * handful of grouped queries (LeadStats), not one query per card.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $stats = new LeadStats($user);

        return view('dashboard', [
            'user' => $user,
            'cards' => $stats->cards(),
            'wonValue' => $stats->wonValue(),
            'pipeline' => $stats->pipeline()->filter(fn ($row) => $row['status']->type->value === 'open' && $row['status']->is_active)->values(),
            'activeTotals' => $stats->activeValue(),
            'upcoming' => $stats->followUps()->where('status', FollowUpStatus::Pending)
                ->with(['lead:id,reference,first_name,last_name,company', 'assignee:id,name'])
                ->orderBy('due_at')->limit(5)->get(),
            'recent' => $stats->recentActivity(),
            'sources' => $stats->bySource()->take(6),
        ]);
    }
}
