<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification centre behind the header bell.
 *
 *   GET  /w/{workspace}/notifications              the full list
 *   POST /w/{workspace}/notifications/read-all     clear the badge
 *   POST /w/{workspace}/notifications/{id}/read    read one, then follow its link
 *
 * WorkspaceNotification is deliberately not tenant-scoped, so every lookup pins
 * BOTH the workspace and the signed-in user before it touches a row — that is what
 * keeps one agent from reading another's (or another tenant's) notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        $notifications = WorkspaceNotification::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('Notifications', [
            'notifications' => $notifications
                ->map(fn (WorkspaceNotification $notification) => $this->payload($notification))
                ->all(),
            'unreadCount' => $notifications->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, string $workspace, int $notification): RedirectResponse
    {
        $model = $this->mine($request, $notification);

        if (! $model->read_at) {
            $model->update(['read_at' => now()]);
        }

        return $model->url ? redirect($model->url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);

        WorkspaceNotification::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    private function mine(Request $request, int $id): WorkspaceNotification
    {
        return WorkspaceNotification::query()
            ->where('id', $id)
            ->where('workspace_id', $this->workspace($request)->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }

    /** @return array<string, mixed> */
    private function payload(WorkspaceNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'level' => $notification->level,
            'title' => $notification->title,
            'body' => $notification->body,
            'url' => $notification->url,
            'readAt' => $notification->read_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->diffForHumans(),
        ];
    }
}
