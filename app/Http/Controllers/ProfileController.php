<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My Profile": change password and manage two-factor authentication.
 * The form actions post to Fortify's routes (user-password.update, two-factor.*),
 * which validate the current password / require password confirmation themselves.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()]);
    }
}
