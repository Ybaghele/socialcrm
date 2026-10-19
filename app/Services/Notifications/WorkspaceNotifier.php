<?php

namespace App\Services\Notifications;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use Illuminate\Support\Collection;

/**
 * Fans a notification out to the people who can act on it.
 *
 * Operational notices (a broadcast finished, the wallet is running low, Meta
 * approved a template) go to owners and admins. A single user can be targeted
 * instead, e.g. telling the owner that someone accepted their invitation.
 *
 * Rows are inserted in one statement and workspace_id/user_id are set explicitly,
 * so this is safe to call from jobs and transactions with no request context.
 */
class WorkspaceNotifier
{
    public function managers(
        Workspace $workspace,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        string $level = 'info',
    ): int {
        $recipients = $workspace->users()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->get();

        return $this->write($workspace, $recipients, $type, $title, $body, $url, $level);
    }

    public function user(
        Workspace $workspace,
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        string $level = 'info',
    ): void {
        $this->write($workspace, new Collection([$user]), $type, $title, $body, $url, $level);
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function write(
        Workspace $workspace,
        Collection $recipients,
        string $type,
        string $title,
        ?string $body,
        ?string $url,
        string $level,
    ): int {
        $now = now();

        $rows = $recipients
            ->unique('id')
            ->map(fn (User $user): array => [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'type' => $type,
                'level' => $level,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows === []) {
            return 0;
        }

        WorkspaceNotification::insert($rows);

        return count($rows);
    }
}
