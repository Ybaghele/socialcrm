<?php

namespace App\Support\Tenancy;

use App\Models\Workspace;

/**
 * Holds "which workspace is this request/job acting for".
 * Registered as a *scoped* binding so it is reset between queue jobs and Octane requests.
 */
final class TenantContext
{
    private ?Workspace $workspace = null;
    private bool $unscoped = false;

    /**
     * The ONE way to get the context. Registers the scoped binding on first use, so the
     * facade, the model scope and the middleware always share the same instance, even if
     * TenancyServiceProvider was never registered.
     */
    public static function current(): self
    {
        $app = app();

        if (! $app->bound(self::class)) {
            $app->scoped(self::class);
        }

        return $app->make(self::class);
    }

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
        $this->unscoped = false;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->getKey();
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    public function forget(): void
    {
        $this->workspace = null;
        $this->unscoped = false;
    }

    /** Run a callback as a specific workspace, then restore whatever context was active. */
    public function run(Workspace $workspace, callable $callback): mixed
    {
        [$prevWorkspace, $prevUnscoped] = [$this->workspace, $this->unscoped];
        $this->set($workspace);

        try {
            return $callback($workspace);
        } finally {
            [$this->workspace, $this->unscoped] = [$prevWorkspace, $prevUnscoped];
        }
    }

    /** Cross-tenant access (webhook account lookup, super-admin, reports). Use sparingly and read-mostly. */
    public function runUnscoped(callable $callback): mixed
    {
        [$prevWorkspace, $prevUnscoped] = [$this->workspace, $this->unscoped];
        $this->workspace = null;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            [$this->workspace, $this->unscoped] = [$prevWorkspace, $prevUnscoped];
        }
    }
}
