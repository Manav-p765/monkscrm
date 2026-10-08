<?php

use App\Http\Controllers\AcceptInviteController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlaceholderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Models\Lead;
use Illuminate\Support\Facades\Route;

/*
| Public routes: Fortify's (login, forgot/reset password, two-factor challenge) and the invite
| set-password page below. Everything else requires authentication, and every role-restricted
| route is protected by a gate/policy (hiding links is not security).
*/

// Invite links (single use, 48 hours). Guests only; rate-limited like the other auth screens.
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('/invite/{token}', [AcceptInviteController::class, 'show'])->name('invite.show');
    Route::post('/invite', [AcceptInviteController::class, 'store'])->name('invite.store');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');

    // Placeholders for sections built in later steps.
    Route::get('/leads', PlaceholderController::class)
        ->defaults('section', 'Leads')
        ->middleware('can:viewAny,'.Lead::class)
        ->name('leads.index');

    Route::get('/pipeline', PlaceholderController::class)
        ->defaults('section', 'Pipeline')
        ->middleware('can:viewAny,'.Lead::class)
        ->name('pipeline.index');

    Route::get('/follow-ups', PlaceholderController::class)
        ->defaults('section', 'Follow-Ups')
        ->name('follow-ups.index');

    Route::get('/imports', PlaceholderController::class)
        ->defaults('section', 'Imports')
        ->middleware('can:import-leads')
        ->name('imports.index');

    Route::get('/reports', PlaceholderController::class)
        ->defaults('section', 'Reports')
        ->middleware('can:view-reports')
        ->name('reports.index');

    // Users & Teams: managers get the read-only list; every change needs manage-users (UserPolicy).
    Route::middleware('can:view-users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::get('/users/{user}/deactivate', [UserController::class, 'confirmDeactivate'])->name('users.deactivate.confirm');
    });
    Route::middleware('can:manage-users')->group(function () {
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/resend-invite', [UserController::class, 'resendInvite'])->name('users.resend-invite');
        Route::post('/users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->name('users.password-reset');
        Route::delete('/users/{user}/two-factor', [UserController::class, 'disableTwoFactor'])->name('users.two-factor.disable');
        Route::delete('/users/{user}/sessions', [UserController::class, 'revokeSessions'])->name('users.sessions.revoke');
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
    });
    Route::middleware('can:manage-teams')->group(function () {
        Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
        Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    });

    Route::get('/settings', PlaceholderController::class)
        ->defaults('section', 'Settings')
        ->middleware('can:manage-settings')
        ->name('settings.index');

    Route::middleware('can:view-audit-log')->group(function () {
        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::get('/audit-log/{auditLog}', [AuditLogController::class, 'show'])->name('audit-log.show');
    });
});

// Unknown URLs: plain 404, no hints about what exists.
Route::fallback(fn () => abort(404));
