<?php

use App\Http\Controllers\AcceptInviteController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LeadBulkController;
use App\Http\Controllers\LeadExportController;
use App\Http\Controllers\LeadWorkController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadDuplicateCheckController;
use App\Http\Controllers\LeadSearchController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Settings\LeadSourceController;
use App\Http\Controllers\Settings\LeadStatusController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\TagController;
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
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Leads. {lead} binds by reference through Lead::visibleTo(): invisible leads are 404.
    Route::middleware('can:viewAny,'.Lead::class)->group(function () {
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
        Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::post('/leads/bulk', LeadBulkController::class)->name('leads.bulk');
        Route::middleware('throttle:live')->group(function () {
            Route::get('/leads/live-search', LeadSearchController::class)->name('leads.live-search');
            Route::get('/leads/duplicate-check', LeadDuplicateCheckController::class)->name('leads.duplicate-check');
        });
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
        Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('leads.status');
        Route::patch('/leads/{lead}/assignee', [LeadController::class, 'updateAssignee'])->name('leads.assignee');
        Route::patch('/leads/{lead}/priority', [LeadController::class, 'updatePriority'])->name('leads.priority');
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
        Route::post('/leads/{lead}/restore', [LeadController::class, 'restore'])->withTrashed()->name('leads.restore');

        Route::get('/pipeline', PipelineController::class)->name('pipeline.index');
    });

    // Lead work (notes, activities, follow-ups). Notes/follow-ups bind through their lead's visibility.
    Route::post('/leads/{lead}/notes', [LeadWorkController::class, 'storeNote'])->name('leads.notes.store');
    Route::put('/notes/{note}', [LeadWorkController::class, 'updateNote'])->name('notes.update');
    Route::delete('/notes/{note}', [LeadWorkController::class, 'destroyNote'])->name('notes.destroy');
    Route::post('/leads/{lead}/activities', [LeadWorkController::class, 'storeActivity'])->name('leads.activities.store');
    Route::post('/leads/{lead}/follow-ups', [LeadWorkController::class, 'storeFollowUp'])->name('leads.follow-ups.store');
    Route::patch('/follow-ups/{followUp}', [LeadWorkController::class, 'updateFollowUp'])->name('follow-ups.update');

    Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('follow-ups.index');
    Route::post('/alerts/seen', [FollowUpController::class, 'markAlertsSeen'])->name('alerts.seen');

    // Imports (admins and managers). Each import is only visible to its importer and admins.
    Route::middleware('can:import-leads')->group(function () {
        Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/template.csv', [ImportController::class, 'template'])->name('imports.template');
        Route::post('/imports', [ImportController::class, 'store'])->middleware('throttle:20,60')->name('imports.store');
        Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('/imports/{import}/mapping', [ImportController::class, 'mapping'])->name('imports.mapping');
        Route::post('/imports/{import}/strategy', [ImportController::class, 'strategy'])->name('imports.strategy');
        Route::post('/imports/{import}/run', [ImportController::class, 'run'])->name('imports.run');
        Route::post('/imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
        Route::post('/imports/{import}/undo', [ImportController::class, 'undo'])->name('imports.undo');
        Route::get('/imports/{import}/errors.csv', [ImportController::class, 'errors'])->name('imports.errors');
    });

    // Export the filtered lead list (admins and managers; 10 per hour per user).
    Route::get('/leads-export', LeadExportController::class)->middleware(['can:export-leads', 'throttle:10,60'])->name('leads.export');

    Route::middleware('can:view-reports')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->middleware('throttle:10,60')->name('reports.export');
    });

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

    // Settings (admin only). Nothing here is ever deleted except tags.
    Route::middleware('can:manage-settings')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'statuses'])->name('index');
        Route::get('/statuses', [SettingsController::class, 'statuses'])->name('statuses');
        Route::get('/sources', [SettingsController::class, 'sources'])->name('sources');
        Route::get('/tags', [SettingsController::class, 'tags'])->name('tags');
        Route::get('/general', [SettingsController::class, 'general'])->name('general');
        Route::put('/general', [SettingsController::class, 'updateGeneral'])->name('general.update');
        Route::get('/permissions', [SettingsController::class, 'permissions'])->name('permissions');

        Route::post('/statuses', [LeadStatusController::class, 'store'])->name('statuses.store');
        Route::post('/statuses/reorder', [LeadStatusController::class, 'reorder'])->name('statuses.reorder');
        Route::put('/statuses/{status}', [LeadStatusController::class, 'update'])->name('statuses.update');
        Route::post('/statuses/{status}/move', [LeadStatusController::class, 'reorder'])->name('statuses.move');
        Route::post('/statuses/{status}/default', [LeadStatusController::class, 'makeDefault'])->name('statuses.default');

        Route::post('/sources', [LeadSourceController::class, 'store'])->name('sources.store');
        Route::post('/sources/reorder', [LeadSourceController::class, 'reorder'])->name('sources.reorder');
        Route::put('/sources/{source}', [LeadSourceController::class, 'update'])->name('sources.update');
        Route::post('/sources/{source}/move', [LeadSourceController::class, 'reorder'])->name('sources.move');
        Route::post('/sources/{source}/default', [LeadSourceController::class, 'makeDefault'])->name('sources.default');

        Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    });

    Route::middleware('can:view-audit-log')->group(function () {
        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::get('/audit-log/{auditLog}', [AuditLogController::class, 'show'])->name('audit-log.show');
    });
});

// Unknown URLs: plain 404, no hints about what exists.
Route::fallback(fn () => abort(404));
