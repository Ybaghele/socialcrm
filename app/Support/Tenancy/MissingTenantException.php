<?php

namespace App\Support\Tenancy;

use LogicException;

/** Thrown when tenant-owned data is touched with no workspace context. Fail closed, never leak. */
class MissingTenantException extends LogicException
{
    public static function for(string $model): self
    {
        return new self("No workspace context while accessing [{$model}]. Set one via Tenancy::run(), the ResolveWorkspace middleware, or the TenantAwareJob trait.");
    }
}
