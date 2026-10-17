<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\Tenancy\CrossTenantWriteException;
use App\Support\Tenancy\MissingTenantException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add to every tenant-owned model.
 *  - Reads are filtered to the current workspace.
 *  - No workspace context  => exception (fail closed), not "return everyone's data".
 *  - Writes can't target a different workspace than the current context.
 */
trait BelongsToWorkspace
{
    protected static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $query) {
            $context = TenantContext::current();

            if ($context->isUnscoped()) {
                return;
            }

            if (! $context->id()) {
                throw MissingTenantException::for($query->getModel()::class);
            }

            $query->where($query->getModel()->qualifyColumn('workspace_id'), $context->id());
        });

        static::creating(function (Model $model) {
            $context = TenantContext::current();

            if ($context->isUnscoped()) {
                return;
            }

            if (! $context->id()) {
                throw MissingTenantException::for($model::class);
            }

            if ($model->workspace_id && (int) $model->workspace_id !== $context->id()) {
                throw new CrossTenantWriteException(
                    sprintf('Attempted to write %s into workspace %d while acting as workspace %d.', $model::class, $model->workspace_id, $context->id())
                );
            }

            $model->workspace_id = $context->id();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
