<?php

use Illuminate\Support\Facades\Broadcast;

// Only members of a workspace may listen to its inbox channel.
Broadcast::channel('workspace.{workspaceId}.inbox', function ($user, int $workspaceId) {
    return (bool) $user && $user->workspaces()->whereKey($workspaceId)->exists();
});
