<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Requires routes/webhooks.php to be registered (see SETUP.md step 4). */
class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config(['omniflow.meta.app_secret' => 'test-secret', 'omniflow.meta.verify_token' => 'verify-me', 'queue.default' => 'sync']);

        $owner = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $owner->id]);
        $this->workspace->users()->attach($owner->id, ['role' => 'owner']);

        $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900, 'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5]);
        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);

        Tenancy::run($this->workspace, function () {
            PipelineStage::create(['name' => 'New Inquiry', 'is_default' => true]);
            ChannelAccount::create(['channel' => 'whatsapp', 'name' => 'Main', 'external_id' => 'PN1']);
        });
    }

    private function payload(string $wamid, string $text = 'Hello'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'PN1'],
            'contacts' => [['profile' => ['name' => 'Ananya'], 'wa_id' => '919820044821']],
            'messages' => [['from' => '919820044821', 'id' => $wamid, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    private function deliver(array $payload, string $secret = 'test-secret')
    {
        $raw = json_encode($payload);

        return $this->call('POST', route('webhooks.meta.receive'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
        ], $raw);
    }

    public function test_verification_handshake_echoes_the_challenge(): void
    {
        $this->get(route('webhooks.meta.verify', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-me', 'hub_challenge' => '12345']))
            ->assertOk()->assertSee('12345');

        $this->get(route('webhooks.meta.verify', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'wrong', 'hub_challenge' => '1']))->assertForbidden();
    }

    public function test_bad_signature_is_rejected_and_nothing_is_stored(): void
    {
        $this->deliver($this->payload('wamid.X'), 'not-the-secret')->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_inbound_message_creates_contact_conversation_and_message_in_the_right_tenant(): void
    {
        $this->deliver($this->payload('wamid.1', 'Is delivery free?'))->assertOk();

        Tenancy::run($this->workspace, function () {
            $contact = Contact::firstOrFail();
            $this->assertSame('Ananya', $contact->name);
            $this->assertSame('+919820044821', $contact->phone);
            $this->assertNotNull($contact->pipeline_stage_id);

            $conversation = Conversation::firstOrFail();
            $this->assertSame(1, $conversation->unread_count);
            $this->assertSame('open', $conversation->status);

            $this->assertSame('Is delivery free?', Message::firstOrFail()->body);
        });
    }

    public function test_the_same_provider_message_is_never_stored_twice(): void
    {
        $this->deliver($this->payload('wamid.DUP', 'first'));
        $this->deliver($this->payload('wamid.DUP', 'meta re-sent with a different body'));
        $this->deliver($this->payload('wamid.DUP', 'first')); // identical retry

        Tenancy::run($this->workspace, fn () => $this->assertSame(1, Message::count()));
    }

    public function test_message_for_an_unknown_channel_account_is_ignored_safely(): void
    {
        $payload = $this->payload('wamid.Z');
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'NOT-OURS';

        $this->deliver($payload)->assertOk();

        $this->assertSame('ignored', WebhookEvent::firstOrFail()->status);
    }

    public function test_inbound_for_a_disconnected_account_is_ignored(): void
    {
        // Disconnecting from Settings → Channels must actually stop ingestion,
        // not just hide the badge (findAccount excludes disconnected accounts).
        Tenancy::run($this->workspace, fn () => ChannelAccount::firstOrFail()->update(['status' => 'disconnected']));

        $this->deliver($this->payload('wamid.DISCONNECTED'))->assertOk();

        $this->assertSame('ignored', WebhookEvent::firstOrFail()->status);

        Tenancy::run($this->workspace, fn () => $this->assertSame(0, Message::count()));
    }
}
