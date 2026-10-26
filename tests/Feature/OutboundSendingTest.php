<?php

namespace Tests\Feature;

use App\Actions\SendOutboundMessage;
use App\Enums\ChannelType;
use App\Enums\MessageStatus;
use App\Exceptions\InsufficientBalance;
use App\Exceptions\OutboundNotAllowed;
use App\Jobs\DeliverOutboundMessage;
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
use App\Models\WalletTransaction;
use App\Models\Workspace;
use App\Services\Inbound\InboundMessageProcessor;
use App\Services\Inbound\StatusUpdate;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundSendingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $author;

    private ChannelAccount $account;

    private Contact $contact;

    private Conversation $conversation;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'omniflow.meta.graph_version' => 'v22.0']);

        $this->author = User::factory()->create();
        $this->ws = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->author->id]);
        $this->ws->users()->attach($this->author->id, ['role' => 'owner']);

        $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900, 'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5]);
        Subscription::create(['workspace_id' => $this->ws->id, 'plan_id' => $plan->id, 'status' => 'active']);
        Wallet::create(['workspace_id' => $this->ws->id, 'balance_paise' => 10000]);

        Tenancy::run($this->ws, function () {
            $this->account = ChannelAccount::create(['channel' => 'whatsapp', 'name' => 'Main', 'external_id' => 'PN1', 'access_token' => 'tok']);
            $this->contact = Contact::create(['name' => 'Ananya', 'phone' => '+919820044821']);
            ContactChannelIdentity::create(['contact_id' => $this->contact->id, 'channel_account_id' => $this->account->id, 'channel' => 'whatsapp', 'external_user_id' => '919820044821']);
            $this->conversation = Conversation::create(['contact_id' => $this->contact->id, 'channel_account_id' => $this->account->id, 'channel' => 'whatsapp', 'status' => 'open', 'last_inbound_at' => now()]);
            $this->template = MessageTemplate::create([
                'channel_account_id' => $this->account->id, 'name' => 'order_ready', 'language' => 'en', 'category' => 'marketing', 'status' => 'approved',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, your order {{2}} is ready']],
            ]);
        });
    }

    /**
     * Http::fake() appends stubs and the first matching one wins, so a catch-all
     * fake in setUp() would shadow the per-test failure/Instagram stubs. Register
     * the default success response only in the tests that actually send.
     */
    private function fakeWhatsAppSuccess(): void
    {
        Http::fake(['graph.facebook.com/*/messages*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);
    }

    private function send(?string $text = null, bool $withTemplate = false, array $vars = [], ?Conversation $conversation = null): Message
    {
        return Tenancy::run($this->ws, fn () => app(SendOutboundMessage::class)->handle(
            $conversation ?? $this->conversation->fresh(), $this->author, $text, $withTemplate ? $this->template : null, $vars,
        ));
    }

    private function balance(): int
    {
        return (int) Wallet::where('workspace_id', $this->ws->id)->value('balance_paise');
    }

    private function closeWindow(): void
    {
        Tenancy::run($this->ws, fn () => $this->conversation->update(['last_inbound_at' => now()->subDays(2)]));
    }

    public function test_free_text_inside_the_window_is_sent_and_free(): void
    {
        $this->fakeWhatsAppSuccess();

        $message = $this->send('Yes, delivery is free');

        Tenancy::run($this->ws, function () use ($message) {
            $fresh = Message::find($message->id);
            $this->assertSame('sent', $fresh->status);
            $this->assertSame('wamid.OUT1', $fresh->external_id);
            $this->assertSame(0, $fresh->cost_paise);
            $this->assertSame($this->author->id, Conversation::first()->assigned_user_id); // reply claims the thread
            $this->assertNotNull(Conversation::first()->first_response_at);
        });

        $this->assertSame(10000, $this->balance());
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v22.0/PN1/messages'
            && $r['type'] === 'text' && $r['to'] === '919820044821' && $r['text']['body'] === 'Yes, delivery is free'
            && $r->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_free_text_outside_the_window_is_refused_and_nothing_is_sent(): void
    {
        $this->closeWindow();

        try {
            $this->send('hello?');
            $this->fail('Expected OutboundNotAllowed');
        } catch (OutboundNotAllowed $e) {
            $this->assertSame('template_required', $e->reason);
        }

        Tenancy::run($this->ws, fn () => $this->assertSame(0, Message::count()));
        Http::assertNothingSent();
    }

    public function test_outbound_messaging_is_blocked_when_disabled_for_staging(): void
    {
        config(['omniflow.meta.outbound_enabled' => false]);

        try {
            $this->send('Hello from staging');
            $this->fail('Expected OutboundNotAllowed');
        } catch (OutboundNotAllowed $e) {
            $this->assertSame('outbound_disabled', $e->reason);
        }

        Tenancy::run($this->ws, fn () => $this->assertSame(0, Message::count()));
        $this->assertSame(10000, $this->balance());
        Http::assertNothingSent();
    }

    public function test_template_outside_the_window_is_sent_and_charged_with_a_ledger_line(): void
    {
        $this->fakeWhatsAppSuccess();
        $this->closeWindow();

        $message = $this->send(withTemplate: true, vars: ['Ananya', '#1042']);

        $this->assertSame(88, $message->cost_paise);
        $this->assertSame(10000 - 88, $this->balance());

        $ledger = WalletTransaction::firstOrFail();
        $this->assertSame(['debit', 88, 9912, 'template_message'], [$ledger->type, $ledger->amount_paise, $ledger->balance_after_paise, $ledger->reason]);
        $this->assertSame($message->id, $ledger->reference_id);

        Tenancy::run($this->ws, fn () => $this->assertSame('Hi Ananya, your order #1042 is ready', Message::find($message->id)->body));
        Http::assertSent(fn ($r) => $r['type'] === 'template' && $r['template']['name'] === 'order_ready'
            && $r['template']['language']['code'] === 'en'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Ananya');
    }

    public function test_insufficient_balance_blocks_the_send_and_changes_nothing(): void
    {
        Wallet::where('workspace_id', $this->ws->id)->update(['balance_paise' => 50]);

        $this->expectException(InsufficientBalance::class);

        try {
            $this->send(withTemplate: true, vars: ['A', 'B']);
        } finally {
            $this->assertSame(50, $this->balance());
            Tenancy::run($this->ws, fn () => $this->assertSame(0, Message::count()));
            $this->assertSame(0, WalletTransaction::count());
            Http::assertNothingSent();
        }
    }

    public function test_permanent_failure_marks_failed_and_refunds_the_wallet(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)]);

        $message = $this->send(withTemplate: true, vars: ['Ananya', '#1']);

        Tenancy::run($this->ws, function () use ($message) {
            $fresh = Message::find($message->id);
            $this->assertSame('failed', $fresh->status);
            $this->assertSame('131047', $fresh->error_code);
        });
        $this->assertSame(10000, $this->balance());
        $this->assertSame(1, WalletTransaction::where('reason', 'refund')->count());
    }

    public function test_async_failed_receipt_from_meta_refunds_once_even_if_repeated(): void
    {
        $this->fakeWhatsAppSuccess();

        $message = $this->send(withTemplate: true, vars: ['Ananya', '#1']);
        $this->assertSame(9912, $this->balance());

        $failed = new StatusUpdate(ChannelType::WhatsApp, 'PN1', 'wamid.OUT1', MessageStatus::Failed, CarbonImmutable::now(), ['code' => 131026, 'title' => 'Undeliverable']);
        $processor = app(InboundMessageProcessor::class);
        $processor->applyStatus($failed);
        $processor->applyStatus($failed);

        $this->assertSame(10000, $this->balance());
        $this->assertSame(1, WalletTransaction::where('reason', 'refund')->count());
        Tenancy::run($this->ws, fn () => $this->assertSame('failed', Message::find($message->id)->status));
    }

    public function test_a_retried_job_never_resends_a_message_that_already_has_a_provider_id(): void
    {
        $this->fakeWhatsAppSuccess();

        $message = $this->send('hi');
        Http::fake(); // reset recorded requests

        DeliverOutboundMessage::dispatchSync($message->id, $this->ws->id);

        Http::assertNothingSent();
    }

    public function test_marketing_template_to_an_opted_out_contact_is_refused(): void
    {
        Tenancy::run($this->ws, fn () => $this->contact->update(['is_opted_in' => false]));

        try {
            $this->send(withTemplate: true, vars: ['A', 'B']);
            $this->fail('Expected OutboundNotAllowed');
        } catch (OutboundNotAllowed $e) {
            $this->assertSame('opted_out', $e->reason);
        }

        $this->assertSame(10000, $this->balance());
    }

    public function test_wrong_variable_count_is_refused(): void
    {
        try {
            $this->send(withTemplate: true, vars: ['only-one']);
            $this->fail('Expected OutboundNotAllowed');
        } catch (OutboundNotAllowed $e) {
            $this->assertSame('template_variables', $e->reason);
        }
    }

    public function test_a_paused_template_is_refused_and_nothing_is_sent(): void
    {
        Tenancy::run($this->ws, fn () => $this->template->update(['is_active' => false]));

        try {
            $this->send(withTemplate: true, vars: ['A', 'B']);
            $this->fail('Expected OutboundNotAllowed');
        } catch (OutboundNotAllowed $e) {
            $this->assertSame('template_paused', $e->reason);
        }

        Tenancy::run($this->ws, fn () => $this->assertSame(0, Message::count()));
        $this->assertSame(10000, $this->balance());
        Http::assertNothingSent();
    }

    public function test_instagram_outside_the_window_is_blocked(): void
    {
        $conversation = Tenancy::run($this->ws, function () {
            $account = ChannelAccount::create(['channel' => 'instagram', 'name' => 'IG', 'external_id' => 'IG1', 'access_token' => 'tok']);
            $contact = Contact::create(['name' => 'Rohan']);
            ContactChannelIdentity::create(['contact_id' => $contact->id, 'channel_account_id' => $account->id, 'channel' => 'instagram', 'external_user_id' => 'S1']);

            return Conversation::create(['contact_id' => $contact->id, 'channel_account_id' => $account->id, 'channel' => 'instagram', 'status' => 'open', 'last_inbound_at' => now()->subDays(3)]);
        });

        try {
            $this->send('hey', conversation: $conversation);
            $this->fail('Expected OutboundNotAllowed');
        } catch (OutboundNotAllowed $e) {
            $this->assertSame('window_closed', $e->reason);
        }
    }

    public function test_instagram_inside_the_window_uses_the_send_api_shape(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'S1', 'message_id' => 'mid.OUT9'], 200)]);

        $conversation = Tenancy::run($this->ws, function () {
            $account = ChannelAccount::create(['channel' => 'instagram', 'name' => 'IG', 'external_id' => 'IG1', 'access_token' => 'tok']);
            $contact = Contact::create(['name' => 'Rohan']);
            ContactChannelIdentity::create(['contact_id' => $contact->id, 'channel_account_id' => $account->id, 'channel' => 'instagram', 'external_user_id' => 'S1']);

            return Conversation::create(['contact_id' => $contact->id, 'channel_account_id' => $account->id, 'channel' => 'instagram', 'status' => 'open', 'last_inbound_at' => now()]);
        });

        $message = $this->send('Thanks for reaching out!', conversation: $conversation);

        Tenancy::run($this->ws, fn () => $this->assertSame('mid.OUT9', Message::find($message->id)->external_id));
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v22.0/me/messages'
            && $r['recipient']['id'] === 'S1' && $r['messaging_type'] === 'RESPONSE' && $r['message']['text'] === 'Thanks for reaching out!');
    }
}
