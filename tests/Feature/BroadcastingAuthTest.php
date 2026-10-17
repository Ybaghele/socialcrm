<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * The private inbox channel: only members of the workspace may authorize a
 * subscription, which is what the Echo client hits at /broadcasting/auth.
 */
class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml pins the broadcaster to "null", so routes/channels.php
        // registered the channel on the null driver during boot. Point the
        // default connection at the real Reverb (Pusher-protocol) driver and
        // re-run the real channel file so we exercise the actual authorizer.
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        $this->member = User::factory()->create();

        $this->workspace = Workspace::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'owner_id' => $this->member->id,
        ]);
        $this->workspace->users()->attach($this->member->id, ['role' => 'owner']);
    }

    public function test_a_member_can_authorize_the_inbox_channel(): void
    {
        $response = $this->actingAs($this->member)->post('/broadcasting/auth', [
            'channel_name' => "private-workspace.{$this->workspace->id}.inbox",
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
        $this->assertArrayHasKey('auth', $response->json());
    }

    public function test_a_non_member_is_forbidden_from_the_inbox_channel(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post('/broadcasting/auth', [
            'channel_name' => "private-workspace.{$this->workspace->id}.inbox",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_a_guest_is_forbidden_from_the_inbox_channel(): void
    {
        $this->post('/broadcasting/auth', [
            'channel_name' => "private-workspace.{$this->workspace->id}.inbox",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
