<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MetaConnectController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WorkspaceController;
use App\Models\Plan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/*
 * Public marketing landing page. Pricing is read from the same `plans` table the
 * billing screen uses so the two can never drift — guarded by hasTable so the
 * page still renders before the database is migrated (and in tests without a
 * schema).
 */
Route::get('/', function () {
    $plans = Schema::hasTable('plans')
        ? Plan::query()
            ->where('is_active', true)
            ->orderBy('price_paise')
            ->get(['name', 'slug', 'price_paise', 'monthly_active_contacts', 'max_instagram_accounts', 'max_agent_seats'])
            ->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'slug' => $plan->slug,
                'priceRupees' => (int) round($plan->price_paise / 100),
                'contacts' => (int) $plan->monthly_active_contacts,
                'instagram' => (int) $plan->max_instagram_accounts,
                'seats' => (int) $plan->max_agent_seats,
            ])
            ->all()
        : [];

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'plans' => $plans,
    ]);
})->name('home');

// Send people straight into their workspace inbox once they sign in. A user with
// no workspace yet goes to the create screen instead of a dead-end dashboard.
Route::get('/dashboard', function () {
    $user = request()->user();
    $workspace = $user?->currentWorkspace ?? $user?->workspaces()->first();

    return $workspace
        ? redirect()->route('inbox', ['workspace' => $workspace->slug])
        : redirect()->route('workspaces.create');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // No tenant context exists yet, so these cannot live under /w/{workspace}.
    Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    // Switch the workspace you land in (profile menu) — no tenant prefix either.
    Route::post('/workspaces/{workspace:slug}/switch', [WorkspaceController::class, 'switch'])
        ->name('workspaces.switch');
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

        // Approved WhatsApp templates (mirrored from Meta) that broadcasts can use.
        Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('/templates/sync', [TemplateController::class, 'sync'])->name('templates.sync');

        // Connect the Meta assets the shared webhook routes inbound traffic to.
        Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::patch('/channels/{channelAccount}', [ChannelController::class, 'update'])->name('channels.update');
        Route::post('/channels/{channelAccount}/verify', [ChannelController::class, 'verify'])->name('channels.verify');
        Route::delete('/channels/{channelAccount}', [ChannelController::class, 'destroy'])->name('channels.destroy');

        // WhatsApp Embedded Signup: the self-service version of the form above.
        // Owner-only, and needs an active plan just like connecting manually.
        Route::get('/channels/meta', [MetaConnectController::class, 'start'])->name('channels.meta.start');

        // Team: seats, roles and pending invitations (owners + admins).
        Route::get('/members', [MemberController::class, 'index'])->name('members.index');
        Route::post('/members/invitations', [MemberController::class, 'store'])->name('members.invitations.store');
        Route::post('/members/invitations/{invitation}/resend', [MemberController::class, 'resend'])->name('members.invitations.resend');
        Route::delete('/members/invitations/{invitation}', [MemberController::class, 'destroyInvitation'])->name('members.invitations.destroy');
        Route::patch('/members/{user}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('/members/{user}', [MemberController::class, 'destroy'])->name('members.destroy');

        // Saved audiences (segments) that broadcasts can target.
        Route::get('/segments', [SegmentController::class, 'index'])->name('segments.index');
        Route::post('/segments/preview', [SegmentController::class, 'preview'])->name('segments.preview');
        Route::post('/segments', [SegmentController::class, 'store'])->name('segments.store');
        Route::patch('/segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
        Route::delete('/segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');

        Route::post('/conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
            ->name('conversations.messages.store');
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])
            ->name('conversations.update');

        Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    });
});

/*
 * WhatsApp Embedded Signup callback. Meta only knows ONE redirect URI per app, so
 * this cannot live under /w/{workspace}; the workspace travels back in the signed
 * `state` parameter and is re-authorised in MetaConnectController::callback().
 */
Route::middleware(['auth', 'verified'])->get(
    '/channels/meta/callback',
    [MetaConnectController::class, 'callback']
)->name('channels.meta.callback');

/*
 * Team invitations. The invitee is not a member yet, so these cannot live under
 * /w/{workspace}; the workspace travels in the hashed token instead. The GET is
 * open so we can send guests through login/register and back.
 */
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->name('invitations.show');

Route::middleware('auth')->post('/invitations/{token}', [InvitationController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->name('invitations.accept');

require __DIR__.'/auth.php';
