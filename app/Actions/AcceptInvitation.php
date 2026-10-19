<?php

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Notifications\WorkspaceNotifier;
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

            // Let the owner know the seat is now filled, but never notify someone
            // who just accepted their own invitation.
            if ($workspace->owner && $workspace->owner->id !== $user->id) {
                app(WorkspaceNotifier::class)->user(
                    $workspace,
                    $workspace->owner,
                    'member_joined',
                    "{$user->name} joined your team",
                    'They accepted your invitation and can now access this workspace.',
                    "/w/{$workspace->slug}/members",
                );
            }

            // The user just took an explicit action for this workspace, so make it
            // the one they land in. They can switch back from the profile menu.
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }
}
