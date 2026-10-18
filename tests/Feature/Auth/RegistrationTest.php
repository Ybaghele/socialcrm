<?php

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing up has to leave the new account with somewhere to go. Without a
 * workspace there is no /w/{slug} route, so the user is created together with the
 * tenant (trial subscription, wallet, pipeline) in one transaction.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CreateWorkspace resolves the trial plan by slug and fails hard if it is
        // absent, so every signup test needs it exactly as production has it.
        Plan::create([
            'name' => 'Growth Plan',
            'slug' => 'growth',
            'price_paise' => 499900,
            'monthly_active_contacts' => 2500,
            'max_instagram_accounts' => 5,
            'max_agent_seats' => 5,
        ]);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        // Sent straight to the first screen that matters: connecting a channel.
        $response->assertRedirect(route('channels.index', ['workspace' => 'test-user'], absolute: false));
    }

    public function test_signup_provisions_a_complete_tenant(): void
    {
        $this->post('/register', [
            'name' => 'Yoshida',
            'email' => 'yoshida@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'workspace_name' => 'Acme Traders',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'yoshida@example.com')->firstOrFail();
        $workspace = Workspace::where('slug', 'acme-traders')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->id, $workspace->owner_id);
        $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
        $this->assertSame(
            WorkspaceRole::Owner->value,
            $workspace->users()->whereKey($user->id)->first()->pivot->role,
        );

        $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame(config('omniflow.trial_plan'), $subscription->plan->slug);
        $this->assertTrue($subscription->trial_ends_at->isFuture());

        // An empty wallet: template messages debit it when campaigns go out.
        $this->assertDatabaseHas('wallets', ['workspace_id' => $workspace->id, 'balance_paise' => 0]);

        // The default sales pipeline ships with every new workspace.
        $stages = Tenancy::run($workspace, fn () => PipelineStage::orderBy('position')->pluck('name'));
        $this->assertSame(
            array_column(config('omniflow.default_pipeline_stages'), 'name'),
            $stages->all(),
        );
    }

    public function test_the_workspace_name_falls_back_to_the_users_name(): void
    {
        $this->post('/register', [
            'name' => 'Sam Lee',
            'email' => 'sam@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workspaces', ['name' => 'Sam Lee', 'slug' => 'sam-lee']);
    }

    public function test_no_half_created_account_survives_a_missing_trial_plan(): void
    {
        Plan::query()->delete();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // The account and the tenant roll back together: a workspace-less user
        // cannot reach a single screen, so they must not be persisted at all.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
    }
}
