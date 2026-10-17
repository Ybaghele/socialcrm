<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Models\ChannelAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Campaigns screen: renders live funnels, creates one from the modal and
 * pauses / resumes without ever leaking across workspaces.
 */
class CampaignPageTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();

        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->member->id]);
        $this->workspace->users()->attach($this->member->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);

        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);
    }

    private function makeCampaign(array $attributes = []): Campaign
    {
        return Tenancy::run($this->workspace, function () use ($attributes) {
            $account = ChannelAccount::firstOrCreate(
                ['external_id' => 'PN1'],
                ['channel' => 'whatsapp', 'name' => 'Main line', 'access_token' => 'tok'],
            );

            $campaign = Campaign::create(array_merge([
                'name' => 'Monsoon Restock Broadcast',
                'description' => 'Sends a pre-approved WhatsApp template.',
                'type' => 'broadcast',
                'channel' => 'whatsapp',
                'status' => 'active',
                'message_body' => 'Hey {{name}}, 20% off this week.',
                'audience_description' => ['label' => 'All opted-in subscribers'],
                'channel_account_id' => $account->id,
                'delivered_count' => 6200,
                'replied_count' => 2400,
                'won_count' => 210,
                'cost_paise' => 180000,
            ], $attributes));

            CampaignTrigger::create([
                'campaign_id' => $campaign->id,
                'type' => 'comment_keyword',
                'match_type' => 'contains',
                'keywords' => ['PRICE', 'LINK'],
                'scope' => ['all_posts' => true],
            ]);

            return $campaign;
        });
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/w/acme/campaigns')->assertRedirect('/login');
    }

    public function test_non_members_get_a_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/w/acme/campaigns')->assertNotFound();
    }

    public function test_a_member_sees_campaigns_and_aggregate_stats(): void
    {
        $this->makeCampaign();

        $this->actingAs($this->member)
            ->get('/w/acme/campaigns')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Campaigns')
                ->has('campaigns', 1)
                ->where('campaigns.0.name', 'Monsoon Restock Broadcast')
                ->where('campaigns.0.status', 'active')
                ->where('campaigns.0.trigger', 'IG comment: PRICE, LINK')
                ->where('campaigns.0.target', 'All opted-in subscribers')
                ->where('campaigns.0.delivered', 6200)
                ->where('campaigns.0.replyRate', '38.7%')
                ->where('campaigns.0.leadsWon', 210)
                ->where('stats.broadcastsSent', 6200)
                ->where('stats.replyRate', 38.7)
                ->where('stats.capturedLeads', 210)
                ->where('stats.costPerAcquisition', 8.57)
                ->where('stats.activeCount', 1)
                ->has('funnels', 3)
            );
    }

    public function test_the_create_modal_stores_a_campaign_with_its_keyword_trigger(): void
    {
        $this->actingAs($this->member)
            ->post('/w/acme/campaigns', [
                'name' => 'Diwali Flash Sale',
                'funnel' => 'instagram_comment',
                'message' => 'Hey {{name}}, here is your 20% code.',
                'keywords' => 'PRICE, OFFER , price',
            ])
            ->assertRedirect();

        $campaign = Tenancy::run($this->workspace, fn () => Campaign::firstWhere('name', 'Diwali Flash Sale'));

        $this->assertNotNull($campaign);
        $this->assertSame('instagram', $campaign->channel);
        $this->assertSame('comment_to_dm', $campaign->type);
        $this->assertSame('active', $campaign->status);
        $this->assertSame($this->workspace->id, $campaign->workspace_id);

        $trigger = CampaignTrigger::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($trigger);
        $this->assertSame('comment_keyword', $trigger->type);
        // Trimmed and de-duplicated.
        $this->assertSame(['PRICE', 'OFFER', 'price'], $trigger->keywords);
    }

    public function test_a_campaign_can_be_paused_and_resumed(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->member)
            ->patch("/w/acme/campaigns/{$campaign->id}", ['status' => 'paused'])
            ->assertRedirect();

        $this->assertSame('paused', $campaign->fresh()->status);

        $this->actingAs($this->member)
            ->patch("/w/acme/campaigns/{$campaign->id}", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', $campaign->fresh()->status);
    }

    public function test_a_member_cannot_touch_another_workspaces_campaign(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::create(['name' => 'Globex', 'slug' => 'globex', 'owner_id' => $otherOwner->id]);

        $foreign = Tenancy::run($other, fn () => Campaign::create([
            'name' => 'Globex Broadcast',
            'type' => 'broadcast',
            'channel' => 'whatsapp',
            'status' => 'active',
        ]));

        $this->actingAs($this->member)
            ->patch("/w/acme/campaigns/{$foreign->id}", ['status' => 'paused'])
            ->assertNotFound();

        $this->assertSame('active', $foreign->fresh()->status);
    }
}
