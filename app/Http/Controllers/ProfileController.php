<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileRequest;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My Profile": edit your own name, job title and phone, change password and manage two-factor authentication.
 * The password and two-step forms post to Fortify's routes (user-password.update, two-factor.*),
 * which validate the current password / require password confirmation themselves.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $user->fill([
            'name' => $data['name'],
            'job_title' => $data['job_title'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);
        $fields = array_keys($user->getDirty());
        $user->save();

        if ($fields !== []) {
            Audit::log('profile.updated', $user, ['fields' => $fields]);
        }

        return redirect()->route('profile.show')->with('status', 'Profile saved');
    }
}
