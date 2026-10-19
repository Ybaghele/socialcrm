<?php

namespace App\Http\Controllers;

use App\Actions\CreateWorkspace;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace management for signed-in users, deliberately NOT under the
 * /w/{workspace} prefix: these are the screens you reach when there is no tenant
 * context yet (a brand-new account, or an account whose workspace was removed).
 *
 *   GET  /workspaces/create   name + create another workspace
 *   POST /workspaces          provisions a fully usable tenant on a free trial
 */
class WorkspaceController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Workspaces/Create', [
            // A friendly starting point for the name field.
            'defaultName' => (string) $request->user()?->name,
            'hasWorkspace' => $request->user()?->workspaces()->exists() ?? false,
            'trialDays' => (int) config('omniflow.trial_days'),
        ]);
    }

    public function store(Request $request, CreateWorkspace $action): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $workspace = $action->handle($request->user(), $data['name']);

        return redirect()
            ->route('channels.index', ['workspace' => $workspace->slug])
            ->with('success', $workspace->name.' is ready — connect a channel to start receiving conversations.');
    }

    /**
     * POST /workspaces/{workspace:slug}/switch
     *
     * Changes which workspace the signed-in user lands in. Used by the profile
     * menu when someone belongs to more than one workspace (e.g. after accepting
     * an invitation).
     */
    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            (bool) $user?->is_super_admin || (bool) $user?->workspaces()->whereKey($workspace->id)->exists(),
            403,
        );

        abort_if($workspace->isSuspended() && ! $user->is_super_admin, 403, 'This workspace has been suspended.');

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return redirect()->route('inbox', ['workspace' => $workspace->slug]);
    }
}
