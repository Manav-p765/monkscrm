<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeamRequest;
use App\Models\Team;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;

/**
 * Admins add and rename teams (route middleware can:manage-teams). Teams are for display and
 * filtering only; they never change who sees which leads. Teams are never deleted.
 */
class TeamController extends Controller
{
    public function store(TeamRequest $request): RedirectResponse
    {
        $team = Team::create(['name' => $request->validated('name')]);
        Audit::log('team.created', $team, ['name' => $team->name]);

        return redirect()->route('users.index')->with('status', "Team “{$team->name}” added");
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        $old = $team->name;
        $team->update(['name' => $request->validated('name')]);

        if ($team->wasChanged('name')) {
            Audit::log('team.updated', $team, ['from' => $old, 'to' => $team->name]);
        }

        return redirect()->route('users.index')->with('status', "Team renamed to “{$team->name}”");
    }
}
