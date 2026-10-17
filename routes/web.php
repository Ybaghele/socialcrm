<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => \Illuminate\Foundation\Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Send people straight into their workspace inbox once they sign in.
Route::get('/dashboard', function () {
    $user = request()->user();
    $workspace = $user?->currentWorkspace ?? $user?->workspaces()->first();

    return $workspace
        ? redirect()->route('inbox', ['workspace' => $workspace->slug])
        : Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
 * Tenant routes. {workspace} is the slug; ResolveWorkspace (aliased "workspace")
 * sets the tenant context and 404s non-members before model binding runs.
 * Never type-hint Workspace $workspace in these controllers.
 */
Route::middleware(['auth', 'verified', 'workspace'])->prefix('w/{workspace}')->group(function () {
    // Billing stays reachable even when the subscription has lapsed, otherwise
    // "subscribed" would redirect to a page it has also locked.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing');
    Route::post('/billing/top-up', [BillingController::class, 'topUp'])->name('billing.top-up');

    Route::middleware('subscribed')->group(function () {
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
        Route::post('/inbox/simulate', [InboxController::class, 'simulate'])->name('inbox.simulate');

        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');

        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');

        // Connect the Meta assets the shared webhook routes inbound traffic to.
        Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::patch('/channels/{channelAccount}', [ChannelController::class, 'update'])->name('channels.update');
        Route::post('/channels/{channelAccount}/verify', [ChannelController::class, 'verify'])->name('channels.verify');
        Route::delete('/channels/{channelAccount}', [ChannelController::class, 'destroy'])->name('channels.destroy');

        Route::post('/conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
            ->name('conversations.messages.store');
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])
            ->name('conversations.update');

        Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    });
});

require __DIR__.'/auth.php';
