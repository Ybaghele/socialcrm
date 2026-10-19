<?php

namespace App\Http\Controllers;

use App\Actions\InviteMember;
use App\Enums\WorkspaceRole;
use App\Exceptions\PlanLimitReached;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team management inside a workspace: seats, roles and pending invitations.
 *
 *   GET    /w/{workspace}/members                          the Team screen
 *   POST   /w/{workspace}/members/invitations              invite an email
 *   POST   /w/{workspace}/members/invitations/{id}/resend  refresh + re-send
 *   DELETE /w/{workspace}/members/invitations/{id}         revoke
 *   PATCH  /w/{workspace}/members/{user}                   change a member's role
 *   DELETE /w/{workspace}/members/{user}                   remove a member
 *
 * Rules: only owners and admins reach any of this. Admins may manage agents but
 * never touch admins or the owner. The owner can never be removed or demoted.
 */
class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);
        $role = $this->authorizeManage($request);
        $limits = PlanLimits::for($workspace);

        $rank = [
            WorkspaceRole::Owner->value => 0,
            WorkspaceRole::Admin->value => 1,
            WorkspaceRole::Agent->value => 2,
        ];

        $members = $workspace->users()
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'jobTitle' => $user->pivot->job_title,
                'isOwner' => $user->id === $workspace->owner_id,
                'isSelf' => $user->id === $request->user()->id,
            ])
            ->sortBy(fn (array $member): string => sprintf('%d-%s', $rank[$member['role']] ?? 3, mb_strtolower($member['name'])))
            ->values();

        $invitations = $workspace->invitations()
            ->with('inviter:id,name')
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WorkspaceInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'invitedBy' => $invitation->inviter?->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
                'isExpired' => $invitation->expires_at->isPast(),
            ]);

        return Inertia::render('Members', [
            'members' => $members,
            'invitations' => $invitations,
            'seats' => ['used' => $limits->seatsUsed(), 'max' => $limits->seatLimit()],
            'currentRole' => $role,
            'roles' => $this->assignableRoles($role),
        ]);
    }

    public function store(Request $request, InviteMember $invite): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $role = $this->authorizeManage($request);

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::in($this->assignableRoles($role))],
        ]);

        try {
            [$invitation, $plain] = $invite->handle($workspace, $request->user(), $data['email'], WorkspaceRole::from($data['role']));
        } catch (PlanLimitReached $e) {
            throw ValidationException::withMessages([
                'email' => 'Your plan has no agent seats left. Upgrade to invite more teammates.',
            ]);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return back()
            ->with('success', 'Invitation sent to '.$invitation->email.'.')
            ->with('invite_link', route('invitations.show', ['token' => $plain]));
    }

    public function resend(Request $request, string $workspace, WorkspaceInvitation $invitation, InviteMember $invite): RedirectResponse
    {
        $current = $this->workspace($request);
        $this->authorizeManage($request);

        // WorkspaceInvitation has no tenant global scope, so verify ownership by hand.
        abort_unless($invitation->workspace_id === $current->id, 404);
        abort_if($invitation->accepted_at !== null, 403, 'This invitation has already been accepted.');

        [$refreshed, $plain] = $invite->handle($current, $request->user(), $invitation->email, WorkspaceRole::from($invitation->role));

        return back()
            ->with('success', 'Invitation re-sent to '.$refreshed->email.'.')
            ->with('invite_link', route('invitations.show', ['token' => $plain]));
    }

    public function destroyInvitation(Request $request, string $workspace, WorkspaceInvitation $invitation): RedirectResponse
    {
        $current = $this->workspace($request);
        $this->authorizeManage($request);

        abort_unless($invitation->workspace_id === $current->id, 404);

        $email = $invitation->email;
        $invitation->delete();

        return back()->with('success', 'Invitation to '.$email.' was revoked.');
    }

    public function update(Request $request, string $workspace, User $user): RedirectResponse
    {
        $current = $this->workspace($request);
        $role = $this->authorizeManage($request);
        $targetRole = $this->memberRoleOrFail($current, $user);

        $data = $request->validate([
            'role' => ['required', Rule::in($this->assignableRoles($role))],
        ]);

        abort_if($user->id === $current->owner_id, 403, 'The workspace owner cannot be changed here.');
        $this->guardAdminTarget($role, $targetRole);

        $current->users()->updateExistingPivot($user->id, ['role' => $data['role']]);

        return back()->with('success', $user->name.' is now '.$data['role'].'.');
    }

    public function destroy(Request $request, string $workspace, User $user): RedirectResponse
    {
        $current = $this->workspace($request);
        $role = $this->authorizeManage($request);
        $targetRole = $this->memberRoleOrFail($current, $user);

        abort_if($user->id === $current->owner_id, 403, 'The workspace owner cannot be removed.');
        abort_if($user->id === $request->user()->id, 403, 'You cannot remove yourself from the workspace.');
        $this->guardAdminTarget($role, $targetRole);

        $current->users()->detach($user->id);

        // Don't strand them on a workspace they can no longer open.
        if ($user->current_workspace_id === $current->id) {
            $user->forceFill(['current_workspace_id' => $user->workspaces()->value('workspaces.id')])->save();
        }

        return back()->with('success', $user->name.' was removed from the workspace.');
    }

    /** Only owners and admins manage the team; agents get a 403. */
    private function authorizeManage(Request $request): string
    {
        $role = $request->attributes->get('workspace_role');

        abort_unless(
            in_array($role, [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value], true)
                || (bool) $request->user()?->is_super_admin,
            403,
            'Only workspace owners and admins can manage the team.',
        );

        // A super admin may not have a pivot row; treat them as an owner.
        return $role ?: WorkspaceRole::Owner->value;
    }

    /** @return array<int, string> */
    private function assignableRoles(string $actorRole): array
    {
        return $actorRole === WorkspaceRole::Owner->value
            ? [WorkspaceRole::Admin->value, WorkspaceRole::Agent->value]
            : [WorkspaceRole::Agent->value];
    }

    /** Admins may only touch agents — never other admins or the owner. */
    private function guardAdminTarget(string $actorRole, string $targetRole): void
    {
        if ($actorRole === WorkspaceRole::Admin->value && $targetRole !== WorkspaceRole::Agent->value) {
            abort(403, 'Admins can only manage agents.');
        }
    }

    private function memberRoleOrFail(Workspace $workspace, User $user): string
    {
        $member = $workspace->users()->whereKey($user->getKey())->first();

        abort_unless($member, 404);

        return $member->pivot->role;
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }
}
