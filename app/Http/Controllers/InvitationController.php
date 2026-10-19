<?php

namespace App\Http\Controllers;

use App\Actions\AcceptInvitation;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invitee's side of a team invitation.
 *
 * These routes deliberately sit OUTSIDE /w/{workspace}: the invitee is not a
 * member yet, so the tenant middleware would 404 them. The workspace travels in
 * the (hashed) token instead.
 *
 *   GET  /invitations/{token}   landing page (guests are sent to login first)
 *   POST /invitations/{token}   accept -> join the workspace
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->find($token);

        if (! $invitation) {
            return $this->invalid('This invitation link is invalid or has expired. Ask your teammate to send a new one.');
        }

        if (! $request->user()) {
            // guest() remembers this invitation as the intended URL, then sends the
            // visitor to login (which links on to registration).
            return redirect()->guest(route('login'));
        }

        // Already accepted: just drop them into the workspace.
        if ($invitation->accepted_at !== null) {
            return redirect()->route('inbox', ['workspace' => $invitation->workspace->slug]);
        }

        if (! $invitation->isPending()) {
            return $this->invalid('This invitation has expired. Ask your teammate to send a new one.');
        }

        if (Str::lower($request->user()->email) !== Str::lower($invitation->email)) {
            return $this->invalid(
                'This invitation was sent to '.$invitation->email.'. You are signed in as '.$request->user()->email.'.',
                true,
            );
        }

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'workspaceName' => $invitation->workspace->name,
            'inviterName' => $invitation->inviter?->name,
            'role' => $invitation->role,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        try {
            $workspace = $accept->handle($request->user(), $token);
        } catch (\DomainException $e) {
            return redirect()
                ->route('invitations.show', ['token' => $token])
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('inbox', ['workspace' => $workspace->slug])
            ->with('success', 'Welcome to '.$workspace->name.' — you have joined the team.');
    }

    private function find(string $token): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::with(['workspace', 'inviter:id,name'])
            ->where('token', hash('sha256', $token))
            ->first();
    }

    private function invalid(string $reason, bool $mismatch = false): Response
    {
        return Inertia::render('Invitations/Invalid', [
            'reason' => $reason,
            'mismatch' => $mismatch,
        ]);
    }
}
