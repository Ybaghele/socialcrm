<?php

namespace Tests\Feature;

use App\Actions\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * /workspaces/create — the screens a signed-in user needs when there is no tenant
 * yet (a brand-new account), or when they want a second workspace. They live
 * outside /w/{workspace} on purpose: there is no tenant context to resolve.
 */
class WorkspaceCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create([
            'name' => 'Growth Plan',
            'slug' => 'growth',
            'price_paise' => 499900,
            'monthly_active_contacts' => 2500,
            'max_instagram_accounts' => 5,
            'max_agent_seats' => 5,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/workspaces/create')->assertRedirect('/login');
        $this->post('/workspaces', ['name' => 'Acme'])->assertRedirect('/login');
    }

    public function test_the_screen_is_rendered_with_a_default_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/workspaces/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Workspaces/Create')
                ->where('defaultName', $user->name)
                ->where('hasWorkspace', false)
                ->where('trialDays', (int) config('omniflow.trial_days')));
    }

    public function test_a_user_can_create_another_workspace(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/workspaces', ['name' => 'Acme Traders']);

        $workspace = $user->workspaces()->firstOrFail();

        $this->assertSame('acme-traders', $workspace->slug);
        $this->assertSame(
            WorkspaceRole::Owner->value,
            $workspace->pivot->role,
        );
        $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
        $response->assertRedirect(route('channels.index', ['workspace' => $workspace->slug], absolute: false));
        $response->assertSessionHas('success');
    }

    public function test_the_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/workspaces/create')
            ->post('/workspaces', ['name' => ''])
            ->assertRedirect('/workspaces/create')
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_a_user_without_a_workspace_is_sent_here_from_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('workspaces.create'));
    }

    public function test_a_user_with_a_workspace_lands_in_their_inbox(): void
    {
        $workspace = $this->makeWorkspace();

        $this->actingAs($workspace->owner)
            ->get('/dashboard')
            ->assertRedirect(route('inbox', ['workspace' => $workspace->slug]));
    }

    private function makeWorkspace(): Workspace
    {
        $owner = User::factory()->create();

        return app(CreateWorkspace::class)->handle($owner, 'Acme');
    }
}
