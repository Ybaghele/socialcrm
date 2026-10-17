<?php

namespace App\Services\Inbound;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** "Round-Robin Inbound Router": next available agent = the one assigned longest ago. */
class RoundRobinAssigner
{
    public function next(Workspace $workspace): ?User
    {
        return DB::transaction(function () use ($workspace) {
            $row = DB::table('workspace_user')
                ->where('workspace_id', $workspace->id)
                ->where('is_available', true)
                ->orderByRaw('last_assigned_at is not null') // never-assigned agents first
                ->orderBy('last_assigned_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return null;
            }

            DB::table('workspace_user')->where('id', $row->id)->update(['last_assigned_at' => now()]);

            return User::find($row->user_id);
        });
    }
}
