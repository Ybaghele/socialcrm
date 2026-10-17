<?php

namespace App\Support\Tenancy;

use App\Models\Workspace;

/**
 * Static shortcut to the current TenantContext.
 * Deliberately NOT a Laravel Facade: facades cache their resolved instance and (in
 * current Laravel) only accept a container key, which broke scoped per-job contexts.
 */
final class Tenancy
{
    public static function set(Workspace $workspace): void
    {
        TenantContext::current()->set($workspace);
    }

    public static function get(): ?Workspace
    {
        return TenantContext::current()->get();
    }

    public static function id(): ?int
    {
        return TenantContext::current()->id();
    }

    public static function forget(): void
    {
        TenantContext::current()->forget();
    }

    /** Run a callback as a specific workspace, then restore the previous context. */
    public static function run(Workspace $workspace, callable $callback): mixed
    {
        return TenantContext::current()->run($workspace, $callback);
    }

    /** Cross-tenant access. Webhook account lookup and super-admin only. */
    public static function runUnscoped(callable $callback): mixed
    {
        return TenantContext::current()->runUnscoped($callback);
    }
}
