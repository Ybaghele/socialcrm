<?php

namespace App\Http\Controllers;

use App\Actions\CreateWorkspace;
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
}
