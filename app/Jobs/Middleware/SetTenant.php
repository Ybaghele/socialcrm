<?php

namespace App\Jobs\Middleware;

use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Closure;

/** Job middleware: restores the tenant context inside a queue worker, then clears it. */
final class SetTenant
{
    public function __construct(private int $workspaceId)
    {
    }

    public function handle(object $job, Closure $next): mixed
    {
        $workspace = Workspace::find($this->workspaceId);

        // Tenant deleted/suspended/unpaid while the job sat in the queue: drop it.
        if (! $workspace || ! $workspace->hasAccess()) {
            $job->delete();

            return null;
        }

        return Tenancy::run($workspace, fn () => $next($job));
    }
}
