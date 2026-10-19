<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\WebhookEventController;
use App\Http\Controllers\Admin\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * Platform (super-admin) console. Required from routes/web.php, so it runs inside
 * the normal "web" group (session + CSRF + Inertia).
 *
 * Cross-tenant by design: {workspace} here is the numeric id, not a slug, and
 * these routes never pass through ResolveWorkspace — so controllers must scope
 * tenant-owned reads themselves (Tenancy::run()/runUnscoped()).
 *
 * Non-staff get a 404 from the "super_admin" middleware, so the URL leaks nothing.
 */
Route::middleware(['auth', 'verified', 'super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('workspaces.show');
    Route::post('/workspaces/{workspace}/suspend', [WorkspaceController::class, 'suspend'])->name('workspaces.suspend');
    Route::post('/workspaces/{workspace}/activate', [WorkspaceController::class, 'activate'])->name('workspaces.activate');
    Route::post('/workspaces/{workspace}/extend', [WorkspaceController::class, 'extend'])->name('workspaces.extend');
    Route::patch('/workspaces/{workspace}/limits', [WorkspaceController::class, 'updateLimits'])->name('workspaces.limits');
    Route::post('/workspaces/{workspace}/credit', [WorkspaceController::class, 'credit'])->name('workspaces.credit');

    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::patch('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

    Route::get('/webhooks', [WebhookEventController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks/{webhookEvent}/retry', [WebhookEventController::class, 'retry'])->name('webhooks.retry');
});
