<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Web\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Web\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Web\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Web\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Web\Admin\UserController as AdminUserController;
use App\Http\Controllers\Web\BillingController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\GoogleCalendarConnectionController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\JiraConnectionController;
use App\Http\Controllers\Web\OrganizationController;
use App\Http\Controllers\Web\OrganizationInvitationController;
use App\Http\Controllers\Web\OtherBrowserSessionsController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\UserProfileController;
use App\Service\PermissionStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', [HomeController::class, 'index']);

Route::get('/shared-report', function () {
    return Inertia::render('SharedReport');
})->name('shared-report');

Route::middleware([
    'auth:web',
    'auth.session',
    'verified',
])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');

    Route::get('/time', function () {
        return Inertia::render('Time');
    })->name('time');

    Route::get('/calendar', function () {
        return Inertia::render('Calendar');
    })->name('calendar');

    Route::get('/timesheet', function () {
        return Inertia::render('Timesheet');
    })->name('timesheet');

    Route::get('/reporting', function () {
        return Inertia::render('Reporting');
    })->name('reporting');

    Route::get('/reporting/detailed', function () {
        return Inertia::render('ReportingDetailed');
    })->name('reporting.detailed');

    Route::get('/reporting/shared', function () {
        return Inertia::render('ReportingShared');
    })->name('reporting.shared');

    Route::get('/projects', function () {
        return Inertia::render('Projects');
    })->name('projects');

    Route::get('/projects/{project}', function () {
        return Inertia::render('ProjectShow');
    })->name('projects.show');

    Route::get('/clients', function () {
        return Inertia::render('Clients');
    })->name('clients');

    Route::get('/members', function () {
        return Inertia::render('Members', [
            'availableRoles' => collect(PermissionStore::roleDefinitions())
                ->map(fn (array $definition, string $key): array => [
                    'key' => $key,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                ])
                ->values()
                ->all(),
        ]);
    })->name('members');

    Route::get('/tags', function () {
        return Inertia::render('Tags');
    })->name('tags');

    Route::get('/import', function () {
        return Inertia::render('Import');
    })->name('import');

    Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::get('/organizations/{organizationId}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::get('/teams/create', function (): RedirectResponse {
        return to_route('organizations.create');
    })->name('teams.create');
    Route::get('/teams/{organizationId}', function (string $organizationId): RedirectResponse {
        return to_route('organizations.show', [$organizationId]);
    })->name('teams.show');
    Route::get('/user/profile', [UserProfileController::class, 'show'])->name('profile.show');

    // Billing, from the customer's side. Stripe Checkout takes the money and Stripe's hosted
    // portal handles cards, invoices and cancellation, so neither is rebuilt here.
    Route::get('/billing', [BillingController::class, 'show'])->name('billing.show');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

    // Note: the OAuth state round-trip needs the session guard, so these live inside the web group
    // Same reason as Google Calendar below: the OAuth state round trip needs the session guard.
    Route::get('/integrations/jira/connect', [JiraConnectionController::class, 'connect'])
        ->name('integrations.jira.connect');
    Route::get('/integrations/jira/callback', [JiraConnectionController::class, 'callback'])
        ->name('integrations.jira.callback');
    Route::get('/integrations/google-calendar/connect', [GoogleCalendarConnectionController::class, 'connect'])
        ->name('integrations.google-calendar.connect');
    Route::get('/integrations/google-calendar/callback', [GoogleCalendarConnectionController::class, 'callback'])
        ->name('integrations.google-calendar.callback');
    Route::delete('/user/other-browser-sessions', [OtherBrowserSessionsController::class, 'destroy'])
        ->name('other-browser-sessions.destroy');

    // Leaving an impersonation is the one admin route an impersonated session must still reach,
    // so it sits outside the admin group - the person holding the session is not an admin.
    Route::post('/admin/stop-impersonating', [AdminImpersonationController::class, 'stop'])
        ->name('admin.stop-impersonating');
});

/*
 * The admin portal. Instance wide rather than organization scoped, so it is gated on
 * EnsureUserIsAdmin rather than on any of the per organization permission checks.
 */
Route::middleware([
    'auth:web',
    'auth.session',
    'verified',
    'admin',
])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('overview');

    Route::get('/organizations', [AdminOrganizationController::class, 'index'])->name('organizations.index');
    Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show'])->name('organizations.show');
    Route::put('/organizations/{organization}', [AdminOrganizationController::class, 'update'])->name('organizations.update');
    Route::delete('/organizations/{organization}', [AdminOrganizationController::class, 'destroy'])->name('organizations.destroy');
    Route::get('/organizations/{organization}/export', [AdminOrganizationController::class, 'export'])->name('organizations.export');
    Route::post('/organizations/{organization}/import', [AdminOrganizationController::class, 'import'])->name('organizations.import');
    Route::get('/organizations/{organization}/billing', [AdminSubscriptionController::class, 'ensureFor'])->name('organizations.billing');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/resend-verification', [AdminUserController::class, 'resendVerification'])->name('users.resend-verification');
    Route::post('/users/{user}/impersonate', [AdminImpersonationController::class, 'start'])->name('users.impersonate');

    Route::get('/billing', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('/billing/create', [AdminSubscriptionController::class, 'create'])->name('subscriptions.create');
    Route::post('/billing', [AdminSubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::get('/billing/{subscription}/edit', [AdminSubscriptionController::class, 'edit'])->name('subscriptions.edit');
    Route::put('/billing/{subscription}', [AdminSubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('/billing/{subscription}', [AdminSubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
    Route::post('/billing/{subscription}/start-trial', [AdminSubscriptionController::class, 'startTrial'])->name('subscriptions.start-trial');

    Route::get('/audits', [AdminSystemController::class, 'audits'])->name('audits.index');
    Route::get('/failed-jobs', [AdminSystemController::class, 'failedJobs'])->name('failed-jobs.index');
    Route::post('/failed-jobs/{uuid}/retry', [AdminSystemController::class, 'retryFailedJob'])->name('failed-jobs.retry');
    Route::delete('/failed-jobs/{uuid}', [AdminSystemController::class, 'deleteFailedJob'])->name('failed-jobs.destroy');
    Route::get('/tokens', [AdminSystemController::class, 'tokens'])->name('tokens.index');
    Route::delete('/tokens/{token}', [AdminSystemController::class, 'revokeToken'])->name('tokens.revoke');
    Route::get('/invitations', [AdminSystemController::class, 'invitations'])->name('invitations.index');
    Route::delete('/invitations/{invitation}', [AdminSystemController::class, 'deleteInvitation'])->name('invitations.destroy');
});

Route::get('/team-invitations/{invitation}', [OrganizationInvitationController::class, 'accept'])
    ->middleware(['signed'])
    ->name('team-invitations.accept'); // Note: legacy naming
Route::get('/organization-invitations/{invitation}', [OrganizationInvitationController::class, 'accept'])
    ->middleware(['signed:relative'])
    ->name('organization-invitations.accept');

Route::get('/users/{user}/verify-email-change', [UserController::class, 'verifyEmailChange'])
    ->middleware(['auth:web', 'signed:relative'])
    ->name('users.verify-email-change');
