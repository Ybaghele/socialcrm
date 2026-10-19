<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Exceptions\PlanLimitReached;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Billing\PlanLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InviteMember
{
    /**
     * @return array{0: WorkspaceInvitation, 1: string} the invitation and the PLAIN token to put in the email link
     *                                                 (only the sha256 hash is stored).
     */
    public function handle(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role = WorkspaceRole::Agent): array
    {
        $email = Str::lower(trim($email));

        $alreadyMember = $workspace->users()->where('users.email', $email)->exists();
        if ($alreadyMember) {
            throw new \DomainException('That person is already on this workspace.');
        }

        // Re-inviting the same email just refreshes the invite, so don't count it as a new seat.
        $existing = $workspace->invitations()->where('email', $email)->first();
        if (! $existing?->isPending() && ! PlanLimits::for($workspace)->canAddSeat()) {
            throw new PlanLimitReached('agent seats');
        }

        $plain = Str::random(48);

        $invitation = WorkspaceInvitation::updateOrCreate(
            ['workspace_id' => $workspace->id, 'email' => $email],
            [
                'role' => $role->value,
                'token' => hash('sha256', $plain),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(max(1, (int) config('omniflow.team.invitation_ttl_days', 7))),
                'accepted_at' => null,
            ],
        );

        $this->send($workspace, $inviter, $invitation, $plain, $email);

        return [$invitation, $plain];
    }

    /**
     * A misconfigured mailer must not lose the invitation: the caller still flashes
     * the invite link, so the owner can share it directly (WhatsApp, SMS, …).
     */
    private function send(Workspace $workspace, User $inviter, WorkspaceInvitation $invitation, string $plain, string $email): void
    {
        try {
            Mail::to($email)->send(new WorkspaceInvitationMail($workspace, $inviter, $invitation, $plain));
        } catch (\Throwable $e) {
            Log::warning('Workspace invitation email failed to send.', [
                'workspace_id' => $workspace->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
