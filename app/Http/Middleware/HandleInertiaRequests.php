<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * These are closures, so they are only evaluated when a page renders. That means
     * the "workspace" attribute set by ResolveWorkspace is always available here, and
     * guests / non-tenant pages simply get nulls.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'workspace' => fn () => $this->workspace($request),
            'channels' => fn () => $this->channels($request),
            'canSimulateInbound' => fn () => $this->canSimulateInbound($request),
            'usage' => fn () => $this->usage($request),
            'navCounts' => fn () => $this->navCounts($request),
            'myWorkspaces' => fn () => $this->myWorkspaces($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'inviteLink' => fn () => $request->session()->get('invite_link'),
            ],
        ];
    }

    /** @return array{id: int, name: string, slug: string, role: ?string} | null */
    private function workspace(Request $request): ?array
    {
        $workspace = $this->workspaceFromRequest($request);

        if (! $workspace) {
            return null;
        }

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'role' => $request->attributes->get('workspace_role'),
        ];
    }

    /**
     * Connected Meta assets, for the header connectivity badges.
     *
     * @return array<int, array{channel: string, name: string, status: string}>
     */
    private function channels(Request $request): array
    {
        if (! $workspace = $this->workspaceFromRequest($request)) {
            return [];
        }

        // Never serialise the model: it carries the (encrypted) access_token.
        return ChannelAccount::query()
            ->orderBy('id')
            ->get(['id', 'channel', 'name', 'status'])
            ->map(fn (ChannelAccount $account) => [
                'channel' => $account->channel,
                'name' => $account->name,
                'status' => $account->status,
            ])
            ->all();
    }

    /** @return array{used: int, limit: ?int, percent: int} | null */
    private function usage(Request $request): ?array
    {
        if (! $workspace = $this->workspaceFromRequest($request)) {
            return null;
        }

        $limits = PlanLimits::for($workspace);
        $used = $limits->activeContactsUsed();
        $limit = $limits->plan()?->monthly_active_contacts;

        return [
            'used' => $used,
            'limit' => $limit,
            'percent' => $limit ? min(100, (int) round($used / $limit * 100)) : 0,
        ];
    }

    /**
     * Badge counts for the tab strip.
     *
     * @return array{unread: int, contacts: int, activeCampaigns: int} | null
     */
    private function navCounts(Request $request): ?array
    {
        if (! $this->workspaceFromRequest($request)) {
            return null;
        }

        return [
            'unread' => (int) Conversation::query()->where('status', 'open')->sum('unread_count'),
            'contacts' => Contact::query()->count(),
            'activeCampaigns' => Campaign::query()->whereIn('status', ['active', 'scheduled'])->count(),
        ];
    }

    private function workspaceFromRequest(Request $request): ?Workspace
    {
        $workspace = $request->attributes->get('workspace');

        return $workspace instanceof Workspace ? $workspace : null;
    }

    /**
     * Every workspace the signed-in user belongs to, for the profile-menu switcher.
     *
     * @return array<int, array{id: int, name: string, slug: string, isCurrent: bool}>
     */
    private function myWorkspaces(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        return $user->workspaces()
            ->orderBy('workspaces.name')
            ->get()
            ->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'isCurrent' => $workspace->id === $user->current_workspace_id,
            ])
            ->all();
    }

    /**
     * Mirrors InboxController::maySimulateInbound() so the header button only
     * renders for users who are actually allowed to POST /inbox/simulate.
     */
    private function canSimulateInbound(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return $request->attributes->get('workspace_role') === WorkspaceRole::Owner->value
            || (bool) $request->user()?->is_super_admin;
    }
}
