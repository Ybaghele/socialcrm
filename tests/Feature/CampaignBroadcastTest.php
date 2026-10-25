<?php

namespace Tests\Feature;

use App\Jobs\Campaigns\ProcessCampaignEnrollments;
use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\ContactChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end WhatsApp broadcast: create it from the modal, enrol the opted-in
 * audience, and deliver the approved template — charging the wallet exactly the
 * same way a manual template reply would.
 */
class CampaignBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private ChannelAccount $account;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'omniflow.meta.graph_version' => 'v22.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.CAMP1']]], 200)]);

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

    private function imageTemplate(): MessageTemplate
    {
        return Tenancy::run($this->workspace, fn () => MessageTemplate::create([
            'channel_account_id' => $this->account->id,
            'name' => 'festive_image_offer',
            'language' => 'en',
            'category' => 'marketing',
            'status' => 'approved',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'IMAGE',
                    'example' => ['header_handle' => ['4::sample']],
                ],
                ['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy our festive offer!'],
            ],
        ]));
    }

    private function contact(string $name, string $phone, bool $optedIn = true): Contact
    {
        return Tenancy::run($this->workspace, function () use ($name, $phone, $optedIn) {
            $contact = Contact::create(['name' => $name, 'phone' => $phone, 'is_opted_in' => $optedIn]);

            ContactChannelIdentity::create([
                'contact_id' => $contact->id,
                'channel_account_id' => $this->account->id,
                'channel' => 'whatsapp',
                'external_user_id' => ltrim($phone, '+'),
            ]);

            return $contact;
        });
    }

    private function makeBroadcast(array $attributes = []): Campaign
    {
        return Tenancy::run($this->workspace, fn () => Campaign::create(array_merge([
            'name' => 'Diwali blast',
            'type' => 'broadcast',
            'channel' => 'whatsapp',
            'status' => 'active',
            'channel_account_id' => $this->account->id,
            'message_template_id' => $this->template->id,
            'template_variables' => ['{{first_name}}'],
            'started_at' => now(),
            'created_by' => $this->owner->id,
        ], $attributes)));
    }

    private function balance(): int
    {
        return (int) Wallet::where('workspace_id', $this->workspace->id)->value('balance_paise');
    }

    public function test_a_broadcast_needs_an_approved_template(): void
    {
        $this->actingAs($this->owner)
            ->post('/w/acme/campaigns', ['name' => 'Diwali blast', 'funnel' => 'whatsapp_broadcast'])
            ->assertSessionHasErrors('template_id');

        $this->assertSame(0, Campaign::withoutGlobalScopes()->count());
    }

    public function test_a_pending_template_cannot_be_broadcast(): void
    {
        $pending = Tenancy::run($this->workspace, fn () => MessageTemplate::create([
            'channel_account_id' => $this->account->id, 'name' => 'pending_one', 'language' => 'en',
            'category' => 'marketing', 'status' => 'pending',
            'components' => [['type' => 'BODY', 'text' => 'hi']],
        ]));

        $this->actingAs($this->owner)
            ->post('/w/acme/campaigns', [
                'name' => 'Nope', 'funnel' => 'whatsapp_broadcast', 'template_id' => $pending->id,
            ])
            ->assertSessionHasErrors('template_id');
    }

    public function test_creating_a_broadcast_snapshots_the_template_and_variables(): void
    {
        $this->actingAs($this->owner)
            ->post('/w/acme/campaigns', [
                'name' => 'Diwali blast',
                'funnel' => 'whatsapp_broadcast',
                'template_id' => $this->template->id,
                'template_variables' => ['{{first_name}}'],
            ])
            ->assertRedirect();

        $campaign = Tenancy::run($this->workspace, fn () => Campaign::firstWhere('name', 'Diwali blast'));

        $this->assertNotNull($campaign);
        $this->assertSame('broadcast', $campaign->type);
        $this->assertSame($this->template->id, $campaign->message_template_id);
        $this->assertSame($this->account->id, $campaign->channel_account_id);
        $this->assertSame(['{{first_name}}'], $campaign->template_variables);
        $this->assertSame('active', $campaign->status);
    }

    public function test_a_campaign_can_choose_its_own_image_for_an_image_template(): void
    {
        Storage::fake('public');
        $template = $this->imageTemplate();
        $this->contact('Ananya Sharma', '+919820044821', true);

        $this->actingAs($this->owner)
            ->post('/w/acme/campaigns', [
                'name' => 'Image blast',
                'funnel' => 'whatsapp_broadcast',
                'template_id' => $template->id,
                'template_variables' => ['{{first_name}}'],
                'header_image' => UploadedFile::fake()->image('campaign.jpg', 1200, 630),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = Tenancy::run($this->workspace, fn () => Campaign::firstWhere('name', 'Image blast'));

        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->header_image_path);
        Storage::disk('public')->assertExists($campaign->header_image_path);

        (new ProcessCampaignEnrollments)->handle();

        $campaign->refresh();
        $this->assertSame(1, (int) $campaign->delivered_count);

        $message = Tenancy::run($this->workspace, fn () => Message::where('campaign_id', $campaign->id)->first());
        $imageUrl = $campaign->headerImageUrl();

        $this->assertNotNull($message);
        $this->assertSame($imageUrl, $message->payload['template']['header_image_url']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/PN1/messages')
            && $request['template']['components'][0] === [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'image',
                    'image' => ['link' => $imageUrl],
                ]],
            ]);
    }

    public function test_an_image_template_without_a_sample_requires_a_campaign_image(): void
    {
        Storage::fake('public');
        $template = $this->imageTemplate();

        $this->actingAs($this->owner)
            ->post('/w/acme/campaigns', [
                'name' => 'No image',
                'funnel' => 'whatsapp_broadcast',
                'template_id' => $template->id,
                'template_variables' => ['{{first_name}}'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('header_image');

        $this->assertSame(0, Campaign::withoutGlobalScopes()->count());
    }

    public function test_a_campaign_falls_back_to_the_template_sample_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('template-media/sample.jpg', 'image-bytes');

        $template = $this->imageTemplate();
        $template->update(['header_image_path' => 'template-media/sample.jpg']);

        $this->contact('Ananya Sharma', '+919820044821', true);

        $campaign = $this->makeBroadcast(['message_template_id' => $template->id]);

        (new ProcessCampaignEnrollments)->handle();

        $message = Tenancy::run($this->workspace, fn () => Message::where('campaign_id', $campaign->id)->first());
        $imageUrl = $template->headerImageUrl();

        $this->assertNotNull($message);
        $this->assertSame($imageUrl, $message->payload['template']['header_image_url']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/PN1/messages')
            && $request['template']['components'][0]['parameters'][0]['image']['link'] === $imageUrl);
    }

    public function test_processing_enrols_only_opted_in_contacts_then_delivers(): void
    {
        $optedIn = $this->contact('Ananya Sharma', '+919820044821', true);
        $this->contact('Opted Out', '+919820044822', false);

        $campaign = $this->makeBroadcast();

        // The scheduler's job: enrol a chunk, then queue the deliveries. On the
        // sync queue those deliveries run inline, so this exercises the whole path.
        (new ProcessCampaignEnrollments)->handle();

        Tenancy::run($this->workspace, function () use ($campaign, $optedIn) {
            $enrollments = CampaignEnrollment::where('campaign_id', $campaign->id)->get();
            $this->assertCount(1, $enrollments);
            $this->assertSame($optedIn->id, $enrollments->first()->contact_id);
            $this->assertSame('completed', $enrollments->first()->status);
            $this->assertNotNull($enrollments->first()->delivered_at);

            $fresh = $campaign->fresh();
            $this->assertSame(1, (int) $fresh->enrolled_count);
            $this->assertSame(1, (int) $fresh->delivered_count);

            $message = Message::where('campaign_id', $campaign->id)->first();
            $this->assertNotNull($message);
            $this->assertSame('template', $message->type);
            // {{first_name}} was expanded for this contact.
            $this->assertStringContainsString('Ananya', $message->body);
            $this->assertStringNotContainsString('{{1}}', $message->body);

            // The broadcast opened an inbox thread for the contact.
            $this->assertNotNull(Conversation::where('contact_id', $optedIn->id)->first());
        });

        // Marketing template => 88 paise charged, exactly like a manual reply.
        $this->assertSame(100000 - 88, $this->balance());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/PN1/messages')
            && $request['type'] === 'template'
            && $request['template']['name'] === 'diwali_festive_offer_2026');
    }

    public function test_a_paused_campaign_is_left_alone(): void
    {
        $this->contact('Ananya', '+919820044821', true);

        $campaign = $this->makeBroadcast(['status' => 'paused']);

        (new ProcessCampaignEnrollments)->handle();

        Tenancy::run($this->workspace, function () use ($campaign) {
            $this->assertSame(0, CampaignEnrollment::where('campaign_id', $campaign->id)->count());
        });

        Http::assertNothingSent();
    }

    public function test_a_campaign_without_an_approved_template_is_left_untouched(): void
    {
        $this->contact('Ananya', '+919820044821', true);

        $campaign = $this->makeBroadcast(['message_template_id' => null]);

        (new ProcessCampaignEnrollments)->handle();

        Tenancy::run($this->workspace, function () use ($campaign) {
            $this->assertSame(0, CampaignEnrollment::where('campaign_id', $campaign->id)->count());
            $this->assertSame('active', $campaign->fresh()->status);
        });

        Http::assertNothingSent();
    }

    public function test_a_disconnected_number_is_skipped_instead_of_enrolled(): void
    {
        $this->contact('Ananya', '+919820044821', true);

        $campaign = $this->makeBroadcast();

        Tenancy::run($this->workspace, fn () => $this->account->update(['status' => 'disconnected']));

        (new ProcessCampaignEnrollments)->handle();

        Tenancy::run($this->workspace, function () use ($campaign) {
            $this->assertSame(0, CampaignEnrollment::where('campaign_id', $campaign->id)->count());
            $this->assertSame(0, (int) $campaign->fresh()->delivered_count);
            $this->assertSame('active', $campaign->fresh()->status);
        });

        Http::assertNothingSent();
    }

    public function test_the_broadcast_completes_once_the_audience_is_done(): void
    {
        $this->contact('Ananya', '+919820044821', true);

        $campaign = $this->makeBroadcast();

        (new ProcessCampaignEnrollments)->handle();

        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->ended_at);
    }
}
