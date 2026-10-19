<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-app notification addressed to ONE user inside ONE workspace.
 *
 * Deliberately NOT tenant-scoped (no BelongsToWorkspace trait): notifications are
 * written from billing code and background jobs that have no request context, and
 * WorkspaceNotifier always sets workspace_id explicitly. Reads always filter on
 * both workspace_id and user_id, so nothing can leak between tenants or users.
 */
class WorkspaceNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }

    /** @param Builder<WorkspaceNotification> $query */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
