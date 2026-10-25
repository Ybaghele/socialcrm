<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The in-app template creator submits straight to Meta. Meta's error.message is
 * usually the generic "Invalid parameter"; the actionable text lives in
 * error_user_msg, so that is what the tenant must see.
 */
class TemplateCreationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'omniflow.meta.app_secret' => 'test-secret',
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
        });
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'channel_account_id' => $this->account->id,
            'name' => 'diwali_offer',
            'category' => 'marketing',
            'language' => 'en_US',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy {{2}} off.'],
            ],
        ];
    }

    public function test_a_meta_rejection_shows_its_actionable_user_message(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'type' => 'OAuthException',
                'code' => 100,
                'error_subcode' => 2388293,
                'error_user_title' => 'Parameters words ratio exceeds limit',
                'error_user_msg' => 'This template has too many variables for its length. Reduce the number of variables or increase the message length.',
            ],
        ], 400)]);

        $this->actingAs($this->owner)
            ->post('/w/acme/templates', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'too many variables for its length')
                && ! str_contains($message, 'Meta rejected this template: Invalid parameter'));

        Tenancy::run($this->workspace, fn () => $this->assertSame(0, MessageTemplate::count()));
    }

    public function test_a_successful_submission_is_mirrored_locally(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates' => Http::response([
            'id' => '123', 'status' => 'PENDING',
        ], 200)]);

        $this->actingAs($this->owner)
            ->post('/w/acme/templates', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::run($this->workspace, function () {
            $template = MessageTemplate::sole();
            $this->assertSame('diwali_offer', $template->name);
            $this->assertSame('pending', $template->status);
            $this->assertSame('123', $template->meta_template_id);
        });
    }
}
