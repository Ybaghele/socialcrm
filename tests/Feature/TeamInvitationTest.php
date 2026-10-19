<?php

namespace Tests\Feature;

use App\Actions\InviteMember;
use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Team seats, roles and invitations: who may invite, who may manage whom, and
 * the invitee's accept journey (which lives outside /w/{workspace}).
 */
class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);

        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id, ['role' => WorkspaceRole::Owner->value]);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);

        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);
    }

    private function addMember(string $role, string $email = null): User
    {
        $user = User::factory()->create(['email' => $email ?? "{$role}@example.com"]);
        $this->workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function invite(string $email, WorkspaceRole $role = WorkspaceRole::Agent): string
    {
        [$invitation, $plain] = app(InviteMember::class)->handle($this->workspace, $this->owner, $email, $role);

        return $plain;
    }

    private function membersUrl(string $suffix = ''): string
    {
        return '/w/acme/members'.$suffix;
    }

    // ---- Screen access -----------------------------------------------------

    public function test_the_team_screen_lists_members_and_seat_usage(): void
    {
        $this->actingAs($this->owner)
            ->get($this->membersUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Members')
                ->has('members', 1)
                ->where('members.0.email', 'ada@example.com')
                ->where('members.0.isOwner', true)
                ->where('seats.used', 1)
                ->where('seats.max', 5)
                ->where('currentRole', 'owner'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get($this->membersUrl())->assertRedirect('/login');
    }

    public function test_an_agent_cannot_open_the_team_screen(): void
    {
        $agent = $this->addMember(WorkspaceRole::Agent->value);

        $this->actingAs($agent)->get($this->membersUrl())->assertForbidden();
    }

    // ---- Inviting ----------------------------------------------------------

    public function test_an_owner_can_invite_a_teammate(): void
    {
        $this->actingAs($this->owner)
            ->post($this->membersUrl('/invitations'), ['email' => 'teammate@example.com', 'role' => 'agent'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('invite_link');

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $this->workspace->id,
            'email' => 'teammate@example.com',
            'role' => 'agent',
            'accepted_at' => null,
        ]);

        // The plain token is never stored — only its sha256 hash.
        $invitation = WorkspaceInvitation::firstOrFail();
        $this->assertSame(64, strlen($invitation->token));

        Mail::assertSent(WorkspaceInvitationMail::class, fn (WorkspaceInvitationMail $mail) => $mail->hasTo('teammate@example.com'));
    }

    public function test_an_admin_can_invite_an_agent(): void
    {
        $admin = $this->addMember(WorkspaceRole::Admin->value, 'admin@example.com');

        $this->actingAs($admin)
            ->post($this->membersUrl('/invitations'), ['email' => 'newbie@example.com', 'role' => 'agent'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workspace_invitations', ['email' => 'newbie@example.com', 'role' => 'agent']);
    }

    public function test_an_admin_cannot_invite_another_admin(): void
    {
        $admin = $this->addMember(WorkspaceRole::Admin->value, 'admin@example.com');

        $this->actingAs($admin)
            ->post($this->membersUrl('/invitations'), ['email' => 'boss@example.com', 'role' => 'admin'])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_an_agent_cannot_invite(): void
    {
        $agent = $this->addMember(WorkspaceRole::Agent->value);

        $this->actingAs($agent)
            ->post($this->membersUrl('/invitations'), ['email' => 'x@example.com', 'role' => 'agent'])
            ->assertForbidden();
    }

    public function test_you_cannot_invite_someone_already_on_the_workspace(): void
    {
        $this->actingAs($this->owner)
            ->post($this->membersUrl('/invitations'), ['email' => 'ada@example.com', 'role' => 'agent'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_inviting_stops_when_the_plan_has_no_seats_left(): void
    {
        // One seat: the owner is already using it.
        $this->workspace->subscription->plan->update(['max_agent_seats' => 1]);

        $this->actingAs($this->owner)
            ->post($this->membersUrl('/invitations'), ['email' => 'overflow@example.com', 'role' => 'agent'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    // ---- Managing members --------------------------------------------------

    public function test_the_owner_can_promote_an_agent_to_admin(): void
    {
        $agent = $this->addMember(WorkspaceRole::Agent->value, 'sam@example.com');

        $this->actingAs($this->owner)
            ->patch($this->membersUrl('/'.$agent->id), ['role' => 'admin'])
            ->assertSessionHasNoErrors();

        $this->assertSame('admin', $this->workspace->users()->whereKey($agent->id)->first()->pivot->role);
    }

    public function test_an_admin_cannot_change_another_admin(): void
    {
        $admin = $this->addMember(WorkspaceRole::Admin->value, 'admin1@example.com');
        $other = $this->addMember(WorkspaceRole::Admin->value, 'admin2@example.com');

        $this->actingAs($admin)
            ->patch($this->membersUrl('/'.$other->id), ['role' => 'agent'])
            ->assertForbidden();

        $this->assertSame('admin', $this->workspace->users()->whereKey($other->id)->first()->pivot->role);
    }

    public function test_the_owner_cannot_be_demoted_or_removed(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->membersUrl('/'.$this->owner->id), ['role' => 'agent'])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->delete($this->membersUrl('/'.$this->owner->id))
            ->assertForbidden();

        $this->assertSame('owner', $this->workspace->users()->whereKey($this->owner->id)->first()->pivot->role);
    }

    public function test_a_member_can_be_removed_and_is_not_stranded(): void
    {
        $agent = $this->addMember(WorkspaceRole::Agent->value, 'sam@example.com');
        $agent->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->actingAs($this->owner)
            ->delete($this->membersUrl('/'.$agent->id))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->workspace->users()->whereKey($agent->id)->first());
        $this->assertNull($agent->fresh()->current_workspace_id);
    }

    // ---- Invitation lifecycle ---------------------------------------------

    public function test_an_invitation_can_be_revoked(): void
    {
        $token = $this->invite('teammate@example.com');
        $invitation = WorkspaceInvitation::firstOrFail();

        $this->actingAs($this->owner)
            ->delete($this->membersUrl('/invitations/'.$invitation->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('workspace_invitations', 0);
        $this->assertNotNull($token);
    }

    public function test_an_invitation_can_be_resent_with_a_fresh_token(): void
    {
        $token = $this->invite('teammate@example.com');
        $invitation = WorkspaceInvitation::firstOrFail();
        $originalHash = $invitation->token;

        $this->actingAs($this->owner)
            ->post($this->membersUrl('/invitations/'.$invitation->id.'/resend'))
            ->assertSessionHas('invite_link');

        $this->assertNotSame($originalHash, $invitation->fresh()->token);
        $this->assertNotSame($token, null);
        Mail::assertSent(WorkspaceInvitationMail::class, 2);
    }

    public function test_an_invitation_from_another_workspace_is_not_reachable(): void
    {
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->owner->id]);
        $foreign = WorkspaceInvitation::create([
            'workspace_id' => $other->id, 'email' => 'x@example.com', 'role' => 'agent',
            'token' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->owner)
            ->delete($this->membersUrl('/invitations/'.$foreign->id))
            ->assertNotFound();
    }

    public function test_the_invitation_email_renders_with_a_working_link(): void
    {
        $token = $this->invite('teammate@example.com', WorkspaceRole::Admin);
        $invitation = WorkspaceInvitation::firstOrFail();

        $html = (new WorkspaceInvitationMail($this->workspace, $this->owner, $invitation, $token))->render();

        $this->assertStringContainsString('Accept invitation', $html);
        $this->assertStringContainsString('/invitations/'.$token, $html);
        $this->assertStringContainsString('Acme', $html);
    }

    // ---- Accepting ---------------------------------------------------------

    public function test_an_invited_user_can_accept_and_lands_in_the_workspace(): void
    {
        $token = $this->invite('invitee@example.com', WorkspaceRole::Admin);
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $this->actingAs($invitee)
            ->post('/invitations/'.$token)
            ->assertRedirect(route('inbox', ['workspace' => 'acme'], absolute: false));

        $member = $this->workspace->users()->whereKey($invitee->id)->first();
        $this->assertNotNull($member);
        $this->assertSame('admin', $member->pivot->role);
        $this->assertSame($this->workspace->id, $invitee->fresh()->current_workspace_id);
    }

    public function test_a_guest_visiting_an_invitation_is_sent_to_login(): void
    {
        $token = $this->invite('invitee@example.com');

        $this->get('/invitations/'.$token)->assertRedirect('/login');
    }

    public function test_an_invitation_sent_to_someone_else_is_rejected(): void
    {
        $token = $this->invite('invitee@example.com');
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->actingAs($stranger)
            ->get('/invitations/'.$token)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Invitations/Invalid')
                ->where('mismatch', true));
    }

    public function test_an_expired_invitation_shows_an_error_page(): void
    {
        $token = $this->invite('invitee@example.com');
        WorkspaceInvitation::firstOrFail()->update(['expires_at' => now()->subDay()]);

        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $this->actingAs($invitee)
            ->get('/invitations/'.$token)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Invalid'));
    }

    // ---- Workspace switching ----------------------------------------------

    public function test_a_member_can_switch_workspaces(): void
    {
        $other = Workspace::create(['name' => 'Beta', 'slug' => 'beta', 'owner_id' => $this->owner->id]);
        $other->users()->attach($this->owner->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($this->owner)
            ->post('/workspaces/beta/switch')
            ->assertRedirect(route('inbox', ['workspace' => 'beta'], absolute: false));

        $this->assertSame($other->id, $this->owner->fresh()->current_workspace_id);
    }

    public function test_a_stranger_cannot_switch_into_a_workspace_they_do_not_belong_to(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post('/workspaces/acme/switch')
            ->assertForbidden();
    }
}
