<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Templates can be paused for the workspace or deleted from Meta. Approval
 * itself still belongs to Meta; these tests cover the local switches around it.
 */
class TemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private ChannelAccount $account;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'omniflow.meta.graph_version' => 'v22.0',
            'omniflow.meta.hosts.whatsapp' => 'https://graph.facebook.com',
        ]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);
        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);

        Tenancy::run($this->workspace, function () {
            $this->account = ChannelAccount::create([
                'channel' => 'whatsapp', 'name' => 'Main line', 'external_id' => 'PN1',
                'business_account_id' => 'WABA1', 'access_token' => 'tok', 'status' => 'connected',
            ]);

            $this->template = MessageTemplate::create([
                'channel_account_id' => $this->account->id,
                'name' => 'diwali_offer', 'language' => 'en_US', 'category' => 'marketing',
                'status' => 'approved', 'meta_template_id' => 'meta-1',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']],
            ]);
        });
    }

    public function test_a_manager_can_pause_and_resume_a_template(): void
    {
        $this->actingAs($this->owner)
            ->patch("/w/acme/templates/{$this->template->id}", ['is_active' => false])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::run($this->workspace, fn () => $this->assertFalse($this->template->fresh()->isActive()));

        $this->actingAs($this->owner)
            ->patch("/w/acme/templates/{$this->template->id}", ['is_active' => true])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::run($this->workspace, fn () => $this->assertTrue($this->template->fresh()->isActive()));
    }

    public function test_an_agent_cannot_pause_or_delete_a_template(): void
    {
        $agent = User::factory()->create();
        $this->workspace->users()->attach($agent->id, ['role' => 'agent']);

        $this->actingAs($agent)
            ->patch("/w/acme/templates/{$this->template->id}", ['is_active' => false])
            ->assertForbidden();

        $this->actingAs($agent)
            ->delete("/w/acme/templates/{$this->template->id}")
            ->assertForbidden();

        Tenancy::run($this->workspace, function () {
            $this->assertTrue($this->template->fresh()->isActive());
            $this->assertModelExists($this->template);
        });
    }

    public function test_deleting_a_template_removes_it_from_meta_and_the_workspace(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates*' => Http::response(['success' => true], 200)]);

        $this->actingAs($this->owner)
            ->delete("/w/acme/templates/{$this->template->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::run($this->workspace, fn () => $this->assertDatabaseMissing('message_templates', ['id' => $this->template->id]));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), 'name=diwali_offer')
            && str_contains($request->url(), 'hsm_id=meta-1'));
    }

    public function test_a_meta_delete_failure_keeps_the_local_template(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates*' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'error_user_msg' => 'The template is currently in use.',
            ],
        ], 400)]);

        $this->actingAs($this->owner)
            ->delete("/w/acme/templates/{$this->template->id}")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'currently in use'));

        Tenancy::run($this->workspace, fn () => $this->assertModelExists($this->template));
    }

    public function test_an_active_campaign_blocks_deletion(): void
    {
        Tenancy::run($this->workspace, fn () => Campaign::create([
            'name' => 'Diwali broadcast',
            'type' => 'broadcast',
            'channel' => 'whatsapp',
            'status' => 'active',
            'channel_account_id' => $this->account->id,
            'message_template_id' => $this->template->id,
        ]));

        Http::fake();

        $this->actingAs($this->owner)
            ->delete("/w/acme/templates/{$this->template->id}")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Pause or complete'));

        Http::assertNothingSent();
        Tenancy::run($this->workspace, fn () => $this->assertModelExists($this->template));
    }

    public function test_another_workspace_cannot_touch_the_template(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::create(['name' => 'Globex', 'slug' => 'globex', 'owner_id' => $otherOwner->id]);

        $foreign = Tenancy::run($other, function () {
            $account = ChannelAccount::create([
                'channel' => 'whatsapp', 'name' => 'Globex line', 'external_id' => 'PN2',
                'business_account_id' => 'WABA2', 'access_token' => 'tok', 'status' => 'connected',
            ]);

            return MessageTemplate::create([
                'channel_account_id' => $account->id,
                'name' => 'globex_offer', 'language' => 'en_US', 'category' => 'marketing',
                'status' => 'approved', 'meta_template_id' => 'meta-2',
                'components' => [['type' => 'BODY', 'text' => 'Hello']],
            ]);
        });

        $this->actingAs($this->owner)
            ->patch("/w/acme/templates/{$foreign->id}", ['is_active' => false])
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->delete("/w/acme/templates/{$foreign->id}")
            ->assertNotFound();

        Tenancy::run($other, fn () => $this->assertTrue($foreign->fresh()->isActive()));
    }

    public function test_a_paused_template_is_not_offered_in_the_broadcast_picker(): void
    {
        Tenancy::run($this->workspace, fn () => $this->template->update(['is_active' => false]));

        $this->actingAs($this->owner)
            ->get('/w/acme/campaigns')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Campaigns')
                ->has('broadcast.templates', 0));
    }
}
