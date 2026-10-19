<?php

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptInvitation
{
    public function handle(User $user, string $plainToken): Workspace
    {
        return DB::transaction(function () use ($user, $plainToken) {
            $invitation = WorkspaceInvitation::where('token', hash('sha256', $plainToken))->lockForUpdate()->first();

            if (! $invitation || ! $invitation->isPending()) {
                throw new \DomainException('This invitation is invalid or has expired.');
            }

            if (Str::lower($user->email) !== Str::lower($invitation->email)) {
                throw new \DomainException('This invitation was sent to a different email address.');
            }

            $workspace = $invitation->workspace;
            $workspace->users()->syncWithoutDetaching([$user->id => ['role' => $invitation->role]]);
            $invitation->update(['accepted_at' => now()]);

            // The user just took an explicit action for this workspace, so make it
            // the one they land in. They can switch back from the profile menu.
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }
}
