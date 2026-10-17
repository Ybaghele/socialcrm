<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes look like /w/{workspace}/inbox where {workspace} is the slug.
 * Do NOT type-hint Workspace in controllers for this param: this middleware must
 * run (and set the tenant) BEFORE SubstituteBindings resolves {contact}, {campaign}, etc.
 * so cross-tenant IDs 404 instead of leaking. See SETUP.md for the priority-list line.
 */
class ResolveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('workspace');
        $workspace = is_string($slug) ? Workspace::where('slug', $slug)->first() : null;
        $user = $request->user();

        // 404 (not 403) for non-members so tenant slugs can't be probed.
        abort_unless($workspace && $user, 404);

        $role = $workspace->users()->whereKey($user->getKey())->first()?->pivot?->role;
        abort_unless($role || $user->is_super_admin, 404);

        abort_if($workspace->isSuspended() && ! $user->is_super_admin, 403, 'This workspace has been suspended.');

        TenantContext::current()->set($workspace);
        URL::defaults(['workspace' => $workspace->slug]);
        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('workspace_role', $role);

        if ($user->current_workspace_id !== $workspace->id && $role) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::current()->forget();
    }
}
