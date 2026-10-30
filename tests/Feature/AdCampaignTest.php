<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Meta ads campaign wizard: drafts must be saveable, re-editable and
 * always scoped to the workspace that owns them.
 */
class AdCampaignTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCampaign(array $attributes = []): AdCampaign
    {
        return Tenancy::run($this->workspace, fn () => AdCampaign::create(array_merge([
            'platform' => 'meta',
            'name' => 'Diwali Sale',
            'objective' => 'OUTCOME_SALES',
            'budget_type' => 'daily',
            'budget_amount' => 500,
            'status' => 'draft',
        ], $attributes)));
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardPayload(array $overrides = []): array
    {
        return array_merge([
            'platform' => 'meta',
            'name' => 'Diwali Sale',
            'objective' => 'OUTCOME_SALES',
            'budget_type' => 'daily',
            'budget_amount' => 500,
            'status' => 'draft',
            'audience_json' => ['age_min' => 18, 'age_max' => 45, 'genders' => [0]],
            'placements_json' => ['facebook' => true, 'instagram' => true],
            'optimization_goal' => 'conversions',
            'format' => 'image',
            'media_url' => 'https://example.com/ad.jpg',
            'primary_text' => 'Big savings this week',
            'headline' => 'Shop now',
            'description' => 'Limited offer',
            'cta' => 'LEARN_MORE',
            'destination_url' => 'https://example.com/offer',
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/w/acme/ads/campaigns')->assertRedirect('/login');
    }

    public function test_saving_a_draft_creates_the_campaign_and_redirects_to_its_edit_page(): void
    {
        $response = $this->actingAs($this->member)
            ->post('/w/acme/ads/campaigns', $this->wizardPayload());

        $campaign = Tenancy::run($this->workspace, fn () => AdCampaign::firstWhere('name', 'Diwali Sale'));

        $this->assertNotNull($campaign);
        $this->assertSame($this->workspace->id, $campaign->workspace_id);

        $response->assertRedirect(route('ads.campaigns.edit', [
            'workspace' => 'acme',
            'campaign' => $campaign->id,
        ]));

        $this->assertDatabaseHas('ad_sets', [
            'campaign_id' => $campaign->id,
            'optimization_goal' => 'conversions',
        ]);

        $this->assertDatabaseHas('ad_creatives', [
            'primary_text' => 'Big savings this week',
            'headline' => 'Shop now',
        ]);
    }

    public function test_the_edit_page_loads_the_saved_campaign_with_its_ad_set_and_creative(): void
    {
        $campaign = $this->makeCampaign();

        $adSet = $campaign->adSets()->create([
            'name' => 'Diwali Sale',
            'audience_json' => ['age_min' => 21],
            'optimization_goal' => 'reach',
        ]);

        $adSet->creatives()->create([
            'name' => 'Diwali Sale',
            'format' => 'image',
            'primary_text' => 'Saved copy',
            'headline' => 'Saved headline',
            'destination_url' => 'https://example.com/saved',
        ]);

        $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$campaign->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ads/CreateCampaignWizard')
                ->where('campaign.id', $campaign->id)
                ->where('campaign.name', 'Diwali Sale')
                ->where('campaign.ad_sets.0.optimization_goal', 'reach')
                ->where('campaign.ad_sets.0.audience_json.age_min', 21)
                ->where('campaign.ad_sets.0.creatives.0.primary_text', 'Saved copy')
                ->where('campaign.ad_sets.0.creatives.0.headline', 'Saved headline')
            );
    }

    public function test_saving_a_draft_again_updates_the_campaign_instead_of_creating_a_duplicate(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->member)
            ->patch("/w/acme/ads/campaigns/{$campaign->id}", $this->wizardPayload(['name' => 'Diwali Sale v2']))
            ->assertRedirect();

        $this->assertSame(1, Tenancy::run($this->workspace, fn () => AdCampaign::count()));
        $this->assertSame('Diwali Sale v2', $campaign->fresh()->name);
    }

    public function test_updating_a_campaign_persists_ad_set_and_creative_changes(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->member)
            ->patch("/w/acme/ads/campaigns/{$campaign->id}", $this->wizardPayload([
                'audience_json' => ['age_min' => 25, 'age_max' => 55],
                'optimization_goal' => 'reach',
                'primary_text' => 'Updated copy',
                'headline' => 'Updated headline',
            ]))
            ->assertRedirect();

        $campaign->refresh();
        $adSet = $campaign->adSets()->first();

        $this->assertNotNull($adSet);
        $this->assertSame(25, $adSet->audience_json['age_min']);
        $this->assertSame('reach', $adSet->optimization_goal);
        $this->assertSame('Updated copy', $adSet->creatives()->first()->primary_text);

        // A second save must not pile up ad sets or creatives.
        $this->actingAs($this->member)
            ->patch("/w/acme/ads/campaigns/{$campaign->id}", $this->wizardPayload())
            ->assertRedirect();

        $this->assertSame(1, $campaign->adSets()->count());
        $this->assertSame(1, $campaign->adSets()->first()->creatives()->count());
    }

    public function test_a_member_cannot_edit_another_workspaces_campaign(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::create(['name' => 'Globex', 'slug' => 'globex', 'owner_id' => $otherOwner->id]);

        $foreign = Tenancy::run($other, fn () => AdCampaign::create([
            'platform' => 'meta',
            'name' => 'Globex Campaign',
            'status' => 'draft',
        ]));

        $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$foreign->id}/edit")
            ->assertNotFound();

        $this->actingAs($this->member)
            ->patch("/w/acme/ads/campaigns/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Globex Campaign', $foreign->fresh()->name);
    }
}
