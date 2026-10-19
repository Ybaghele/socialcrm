<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Segment;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\Audiences\SegmentQuery;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Saved audiences (segments) and scheduled broadcasts.
 *
 * SegmentQuery is the single source of truth for both the "N match" preview and
 * the audience a broadcast enrols, so these tests pin that query's behaviour and
 * then prove the broadcast pipeline honours a chosen segment + a send time.
 */
class SegmentAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $owner;
    private ChannelAccount $account;
    private MessageTemplate $template;
    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'omniflow.meta.graph_version' => 'v22.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SEG1']]], 200)]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);
        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);
        Wallet::create(['workspace_id' => $this->workspace->id, 'balance_paise' => 100000]);

        Tenancy::run($this->workspace, function () {
            $this->account = ChannelAccount::create([
                'channel' => 'whatsapp', 'name' => 'Main line', 'external_id' => 'PN1',
                'business_account_id' => 'WABA1', 'access_token' => 'tok', 'status' => 'connected',
            ]);

            $this->template = MessageTemplate::create([
                'channel_account_id' => $this->account->id,
                'name' => 'diwali_festive_offer_2026', 'language' => 'en',
                'category' => 'marketing', 'status' => 'approved',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy our festive offer!']],
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function contact(array $attributes = []): Contact
    {
        $this->phoneSeq++;

        return Tenancy::run($this->workspace, fn () => Contact::create(array_merge([
            'name' => 'Lead '.$this->phoneSeq,
            'phone' => '+9190000'.str_pad((string) $this->phoneSeq, 5, '0', STR_PAD_LEFT),
            'is_opted_in' => true,
        ], $attributes)));
    }

    private function tag(string $name): Tag
    {
        return Tenancy::run($this->workspace, fn () => Tag::create(['name' => $name, 'color' => '#334155']));
    }

    private function stage(string $name): PipelineStage
    {
        return Tenancy::run($this->workspace, fn () => PipelineStage::create(['name' => $name, 'position' => 1]));
    }

    /** @param array<int, array<string, mixed>> $rules */
    private function segment(array $rules): Segment
    {
        return Tenancy::run($this->workspace, fn () => Segment::create([
            'name' => 'Audience',
            'filters' => ['all' => $rules],
            'cached_count' => SegmentQuery::make(['all' => $rules])->count(),
        ]));
    }

    /** SegmentQuery needs a tenant context, exactly as it has at request/job time. */
    private function audience(Segment $segment): int
    {
        return Tenancy::run($this->workspace, fn () => SegmentQuery::for($segment)->count());
    }

    /** @param array<string, mixed> $overrides */
    private function broadcast(array $overrides = []): Campaign
    {
        return Tenancy::run($this->workspace, fn () => Campaign::create(array_merge([
            'name' => 'Blast', 'type' => 'broadcast', 'channel' => 'whatsapp', 'status' => 'active',
            'channel_account_id' => $this->account->id, 'message_template_id' => $this->template->id,
            'template_variables' => ['{{first_name}}'], 'started_at' => now(),
            'created_by' => $this->owner->id,
        ], $overrides)));
    }

    // ---- The query ---------------------------------------------------------

    public function test_a_segment_counts_only_opted_in_contacts(): void
    {
        $this->contact(['is_opted_in' => true]);
        $this->contact(['is_opted_in' => false]);

        $segment = $this->segment([]);

        $this->assertSame(1, $segment->cached_count);
        $this->assertSame(1, $this->audience($segment));
    }

    public function test_a_segment_can_match_by_tag(): void
    {
        $vip = $this->tag('VIP');

        $tagged = $this->contact();
        $tagged->tags()->attach($vip->id);

        $this->contact();

        $segment = $this->segment([['field' => 'tag', 'op' => 'in', 'value' => [$vip->id]]]);

        $this->assertSame(1, $this->audience($segment));
    }

    public function test_a_segment_can_exclude_a_tag(): void
    {
        $blocked = $this->tag('Blocked');

        $excluded = $this->contact();
        $excluded->tags()->attach($blocked->id);

        $this->contact();

        $segment = $this->segment([['field' => 'tag', 'op' => 'not_in', 'value' => [$blocked->id]]]);

        $this->assertSame(1, $this->audience($segment));
    }

    public function test_a_segment_can_match_stage_and_city(): void
    {
        $stage = $this->stage('Hot Lead');

        $this->contact(['pipeline_stage_id' => $stage->id, 'city' => 'Mumbai']);
        $this->contact(['pipeline_stage_id' => $stage->id, 'city' => 'Pune']);
        $this->contact(['city' => 'Mumbai']);

        $segment = $this->segment([
            ['field' => 'pipeline_stage', 'op' => 'in', 'value' => [$stage->id]],
            ['field' => 'city', 'op' => 'in', 'value' => ['Mumbai']],
        ]);

        $this->assertSame(1, $this->audience($segment));
    }

    public function test_a_segment_can_match_a_recent_interaction_window(): void
    {
        $this->contact(['last_interaction_at' => now()->subDays(3)]);
        $this->contact(['last_interaction_at' => now()->subDays(40)]);

        $segment = $this->segment([['field' => 'last_interaction_days', 'op' => 'in', 'value' => 30]]);

        $this->assertSame(1, $this->audience($segment));
    }

    public function test_an_unknown_rule_is_ignored_instead_of_fatal(): void
    {
        $this->contact();

        $segment = $this->segment([['field' => 'nonsense', 'op' => 'in', 'value' => ['x']]]);

        $this->assertSame(1, $this->audience($segment));
    }

    // ---- The screen + endpoints -------------------------------------------

    public function test_the_preview_counts_the_rules_live(): void
    {
        $vip = $this->tag('VIP');

        $tagged = $this->contact();
        $tagged->tags()->attach($vip->id);

        $this->contact();

        $this->actingAs($this->owner)
            ->postJson('/w/acme/segments/preview', [
                'rules' => [['field' => 'tag', 'op' => 'in', 'value' => [$vip->id]]],
            ])
            ->assertOk()
            ->assertJson(['count' => 1]);
    }

    public function test_saving_a_segment_stores_the_cached_count_and_rules(): void
    {
        $vip = $this->tag('VIP');

        $tagged = $this->contact();
        $tagged->tags()->attach($vip->id);

        $this->actingAs($this->owner)
            ->post('/w/acme/segments', [
                'name' => 'VIP customers',
                'rules' => [['field' => 'tag', 'op' => 'in', 'value' => [$vip->id]]],
            ])
            ->assertRedirect();

        $segment = Segment::withoutGlobalScopes()->where('name', 'VIP customers')->firstOrFail();

        $this->assertSame(1, $segment->cached_count);
        $this->assertSame(
            [['field' => 'tag', 'op' => 'in', 'value' => [$vip->id]]],
            $segment->filters['all'],
        );
    }

    public function test_the_segments_screen_lists_saved_audiences(): void
    {
        $this->segment([]);

        $this->actingAs($this->owner)
            ->get('/w/acme/segments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Segments')
                ->has('segments', 1)
                ->where('segments.0.name', 'Audience')
                ->has('options.tags')
            );
    }

    public function test_a_segment_from_another_workspace_cannot_be_edited(): void
    {
        $beta = Workspace::create(['name' => 'Beta', 'slug' => 'beta', 'owner_id' => $this->owner->id]);

        $foreign = Tenancy::run($beta, fn () => Segment::create([
            'name' => 'Beta list',
            'filters' => ['all' => []],
        ]));

        $this->actingAs($this->owner)
            ->patch("/w/acme/segments/{$foreign->id}", ['name' => 'Hijack', 'rules' => []])
            ->assertNotFound();
    }

    // ---- Broadcasts --------------------------------------------------------

    public function test_a_broadcast_enrols_only_the_segment_audience(): void
    {
        $vip = $this->tag('VIP');

        foreach (range(1, 2) as $ignored) {
            $this->contact()->tags()->attach($vip->id);
        }

        $this->contact(); // opted in, but not in the segment

        $segment = $this->segment([['field' => 'tag', 'op' => 'in', 'value' => [$vip->id]]]);

        $this->actingAs($this->owner)
            ->post('/w/acme/campaigns', [
                'name' => 'VIP blast',
                'funnel' => 'whatsapp_broadcast',
                'template_id' => $this->template->id,
                'template_variables' => ['{{first_name}}'],
                'segment_id' => $segment->id,
            ])
            ->assertRedirect();

        $campaign = Campaign::withoutGlobalScopes()->where('name', 'VIP blast')->firstOrFail();
        $this->assertSame($segment->id, $campaign->segment_id);

        $this->artisan('campaigns:process')->assertSuccessful();

        $this->assertSame(2, CampaignEnrollment::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_scheduled_broadcast_waits_for_its_time(): void
    {
        $this->contact();

        $campaign = $this->broadcast(['status' => 'scheduled', 'scheduled_at' => now()->addDay(), 'started_at' => null]);

        $this->artisan('campaigns:process')->assertSuccessful();

        $this->assertSame('scheduled', $campaign->refresh()->status);
        $this->assertSame(0, CampaignEnrollment::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_scheduled_broadcast_fires_once_due(): void
    {
        $this->contact();

        $campaign = $this->broadcast(['status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'started_at' => null]);

        $this->artisan('campaigns:process')->assertSuccessful();

        $campaign->refresh();

        // A one-contact audience is enrolled and sent within the first tick, so the
        // campaign may already read "completed" — what matters is that it left the
        // "scheduled" state, started, and actually enrolled the contact.
        $this->assertContains($campaign->status, ['active', 'completed']);
        $this->assertNotNull($campaign->started_at);
        $this->assertSame(1, CampaignEnrollment::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_schedule_in_the_past_is_rejected(): void
    {
        $this->contact();

        $this->actingAs($this->owner)
            ->post('/w/acme/campaigns', [
                'name' => 'Too late',
                'funnel' => 'whatsapp_broadcast',
                'template_id' => $this->template->id,
                'template_variables' => ['{{first_name}}'],
                'scheduled_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('scheduled_at');

        $this->assertSame(0, Campaign::withoutGlobalScopes()->count());
    }

    public function test_resuming_a_scheduled_broadcast_keeps_it_on_the_clock(): void
    {
        $campaign = $this->broadcast(['status' => 'scheduled', 'scheduled_at' => now()->addDay(), 'started_at' => null]);

        $this->actingAs($this->owner)
            ->patch("/w/acme/campaigns/{$campaign->id}", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('scheduled', $campaign->refresh()->status);
    }
}
