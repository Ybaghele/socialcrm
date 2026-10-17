<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\SetTenant;
use App\Models\Workspace;

/**
 * Use on every queued job that touches tenant data.
 *
 * RULE: pass IDs to the constructor, never tenant-scoped Eloquent models.
 * SerializesModels re-fetches models while the job is being unserialized,
 * which happens BEFORE job middleware sets the tenant, and would throw.
 */
trait TenantAwareJob
{
    public int $workspaceId;

    public function forWorkspace(Workspace|int $workspace): static
    {
        $this->workspaceId = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return $this;
    }

    public function middleware(): array
    {
        return [new SetTenant($this->workspaceId)];
    }
}
