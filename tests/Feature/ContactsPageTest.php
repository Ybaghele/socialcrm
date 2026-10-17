<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\ContactChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Lead CRM directory: renders for members, hides from outsiders, filters
 * server-side and streams a CSV export.
 */
class ContactsPageTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $member;
    private Contact $contact;

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

        Tenancy::run($this->workspace, function () {
            $stage = PipelineStage::create(['name' => 'Hot Lead', 'color' => '#f59e0b', 'position' => 1]);
            $account = ChannelAccount::create(['channel' => 'whatsapp', 'name' => 'Main line', 'external_id' => 'PN1', 'access_token' => 'tok']);

            $this->contact = Contact::create([
                'name' => 'Ananya Sharma',
                'phone' => '+919820044821',
                'city' => 'Mumbai',
                'state' => 'MH',
                'pipeline_stage_id' => $stage->id,
                'last_interaction_at' => now(),
            ]);

            ContactChannelIdentity::create([
                'contact_id' => $this->contact->id,
                'channel_account_id' => $account->id,
                'channel' => 'whatsapp',
                'external_user_id' => '919820044821',
                'handle' => '@ananya',
            ]);

            Tag::create(['name' => 'vip']);

            $conversation = Conversation::create([
                'contact_id' => $this->contact->id,
                'channel_account_id' => $account->id,
                'channel' => 'whatsapp',
                'status' => 'open',
                'last_inbound_at' => now(),
            ]);

            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'sender_type' => 'contact',
                'type' => 'text',
                'body' => 'Hello!',
            ]);
        });
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/w/acme/contacts')->assertRedirect('/login');
    }

    public function test_non_members_get_a_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/w/acme/contacts')->assertNotFound();
    }

    public function test_a_member_sees_the_directory_with_counts(): void
    {
        $this->actingAs($this->member)
            ->get('/w/acme/contacts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts')
                ->where('stats.total', 1)
                ->where('stats.optedIn', 1)
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Ananya Sharma')
                ->where('contacts.data.0.messagesCount', 1)
                ->where('contacts.data.0.channels', ['whatsapp'])
                ->where('contacts.data.0.stage.name', 'Hot Lead')
                ->has('pipelineStages', 1)
            );
    }

    public function test_the_channel_filter_narrows_the_directory(): void
    {
        $this->actingAs($this->member)
            ->get('/w/acme/contacts?channel=instagram')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('contacts.data', 0));

        $this->actingAs($this->member)
            ->get('/w/acme/contacts?q=ananya')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('contacts.data', 1));
    }

    public function test_the_export_streams_a_csv_with_the_header_row(): void
    {
        $response = $this->actingAs($this->member)->get('/w/acme/contacts/export');

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // fputcsv quotes any field containing a space, hence the quoted headers.
        $this->assertStringContainsString('Name,Phone,Email,Channels,"Pipeline stage",Tags,"Total DMs"', $csv);
        $this->assertStringContainsString('Ananya Sharma', $csv);
        $this->assertStringContainsString('Hot Lead', $csv);
    }
}
