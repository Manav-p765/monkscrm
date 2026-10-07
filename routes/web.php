<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlaceholderController;
use App\Http\Controllers\ProfileController;
use App\Models\Lead;
use Illuminate\Support\Facades\Route;

/*
| Public routes are registered by Fortify only: login, forgot/reset password,
| and the two-factor challenge. Everything below requires authentication, and
| every role-restricted route is protected by a gate/policy (hiding links is not security).
*/

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

    Route::get('/users', PlaceholderController::class)
        ->defaults('section', 'Users & Teams')
        ->middleware('can:view-users')
        ->name('users.index');

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
