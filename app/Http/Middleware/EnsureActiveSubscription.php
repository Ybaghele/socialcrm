<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apply to product routes (inbox, CRM, campaigns) but NOT to billing routes. */
class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->attributes->get('workspace');

        if ($workspace && ! $workspace->hasAccess() && ! $request->user()?->is_super_admin) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Subscription required.'], 402)
                : redirect("/w/{$workspace->slug}/billing");
        }

        return $next($request);
    }
}
