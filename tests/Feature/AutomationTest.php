<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\ActivityLog;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
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
use App\Services\Inbound\InboundMessage;
use App\Services\Inbound\InboundMessageProcessor;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Automations: keyword auto-replies, routing rules and follow-up sequences.
 *
 * The sequences are driven by `automations:process`, which the scheduler runs every
 * minute. A reply must always stop a chase, and no rule may ever reach a contact who
 * has opted out.
 */
class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $agent;

    private ChannelAccount $account;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'omniflow.meta.graph_version' => 'v22.0']);
        Http::fake(['graph.facebook.com/*/messages*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->admin = User::factory()->create(['name' => 'Admin']);
        $this->agent = User::factory()->create(['name' => 'Agent']);

        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner', 'is_available' => true]);
        $this->workspace->users()->attach($this->admin->id, ['role' => 'admin', 'is_available' => true]);
        $this->workspace->users()->attach($this->agent->id, ['role' => 'agent', 'is_available' => true]);

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
                'name' => 'festive_offer', 'language' => 'en', 'category' => 'marketing', 'status' => 'approved',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy our festive offer!']],
            ]);
        });
    }

    // ---- Helpers -----------------------------------------------------------

    /** @param array<string, mixed> $attributes */
    private function automation(string $type, string $name, array $attributes = []): Automation
    {
        return Tenancy::run($this->workspace, fn () => Automation::create(array_merge([
            'name' => $name,
            'type' => $type,
            'status' => 'active',
            'priority' => 0,
            'conditions' => [],
            'actions' => null,
            'created_by' => $this->owner->id,
        ], $attributes)));
    }

    private function step(Automation $automation, int $position, int $delayMinutes, ?MessageTemplate $template = null): AutomationStep
    {
        return AutomationStep::create([
            'automation_id' => $automation->id,
            'position' => $position,
            'delay_minutes' => $delayMinutes,
            'message_template_id' => ($template ?? $this->template)->id,
            'template_variables' => ['{{first_name}}'],
        ]);
    }

    /** Push a message through the real inbound pipeline. */
    private function inbound(string $body, string $from = '919000000001'): ?Message
    {
        return app(InboundMessageProcessor::class)->handle(new InboundMessage(
            channel: ChannelType::WhatsApp,
            accountExternalId: $this->account->external_id,
            senderExternalId: $from,
            senderName: 'Test Lead',
            senderPhone: '+'.$from,
            externalMessageId: 'wamid.'.uniqid(),
            type: 'text',
            body: $body,
            payload: ['text' => ['body' => $body]],
            sentAt: CarbonImmutable::now(),
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function contact(array $overrides = []): Contact
    {
        return Tenancy::run($this->workspace, function () use ($overrides) {
            $contact = Contact::create(array_merge([
                'name' => 'Ananya Sharma', 'phone' => '+919000000002', 'is_opted_in' => true,
            ], $overrides));

            // Without an address on the channel there is nothing to send to.
            ContactChannelIdentity::create([
                'contact_id' => $contact->id,
                'channel_account_id' => $this->account->id,
                'channel' => 'whatsapp',
                'external_user_id' => ltrim((string) $contact->phone, '+'),
                'last_inbound_at' => now()->subHours(3),
            ]);

            return $contact;
        });
    }

    private function conversation(Contact $contact): Conversation
    {
        return Tenancy::run($this->workspace, fn () => Conversation::create([
            'contact_id' => $contact->id,
            'channel_account_id' => $this->account->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]));
    }

    /** @return array<int, Message> */
    private function outbound(): array
    {
        return Tenancy::run($this->workspace, fn () => Message::query()
            ->where('direction', 'outbound')
            ->orderBy('id')
            ->get()
            ->all());
    }

    private function setHours(array $hours): void
    {
        $settings = $this->workspace->settings ?? [];
        $settings['automation_business_hours'] = $hours;
        $this->workspace->forceFill(['settings' => $settings])->save();
        $this->workspace->refresh();
    }

    // ---- Auto-replies ------------------------------------------------------

    public function test_a_keyword_rule_replies_to_an_inbound_message(): void
    {
        $this->automation('auto_reply', 'Pricing', [
            'conditions' => ['keywords' => ['price'], 'channels' => []],
            'actions' => ['reply' => 'Our price list is attached.'],
        ]);

        $this->inbound('Hi, what is the price?');

        $messages = $this->outbound();

        $this->assertCount(1, $messages);
        $this->assertSame('Our price list is attached.', $messages[0]->body);
        $this->assertSame('bot', $messages[0]->sender_type);
        $this->assertNull($messages[0]->user_id);
    }

    public function test_a_rule_only_replies_when_its_keywords_match(): void
    {
        $this->automation('auto_reply', 'Pricing', [
            'conditions' => ['keywords' => ['price']],
            'actions' => ['reply' => 'Our price list is attached.'],
        ]);

        $this->inbound('Just saying hello');

        $this->assertCount(0, $this->outbound());
    }

    public function test_an_auto_reply_does_not_fire_twice_within_the_cooldown(): void
    {
        $this->automation('auto_reply', 'Pricing', [
            'conditions' => ['keywords' => ['price']],
            'actions' => ['reply' => 'Our price list is attached.'],
        ]);

        $this->inbound('price?');
        $this->inbound('price again?');

        $this->assertCount(1, $this->outbound());
    }

    public function test_a_first_message_rule_only_greets_new_threads(): void
    {
        $this->automation('auto_reply', 'Welcome', [
            'conditions' => ['only_first_message' => true],
            'actions' => ['reply' => 'Welcome aboard!'],
        ]);

        $this->inbound('hello');
        $this->inbound('anyone there?');

        $messages = $this->outbound();

        $this->assertCount(1, $messages);
        $this->assertSame('Welcome aboard!', $messages[0]->body);
    }

    public function test_an_out_of_hours_rule_only_fires_when_the_workspace_is_closed(): void
    {
        $this->automation('auto_reply', 'Away', [
            'conditions' => ['outside_business_hours' => true],
            'actions' => ['reply' => 'We are closed right now.'],
        ]);

        // Wide open: every day, all day.
        $this->setHours(['timezone' => 'Asia/Kolkata', 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59']);
        $this->inbound('are you open?');

        $this->assertCount(0, $this->outbound());

        // Closed: no open days at all.
        $this->setHours(['timezone' => 'Asia/Kolkata', 'days' => [], 'start' => '10:00', 'end' => '19:00']);
        $this->inbound('are you open now?');

        $messages = $this->outbound();

        $this->assertCount(1, $messages);
        $this->assertSame('We are closed right now.', $messages[0]->body);
    }

    public function test_the_first_matching_rule_wins_and_catch_alls_stay_silent_after_it(): void
    {
        $this->automation('auto_reply', 'Refunds', [
            'priority' => 0,
            'conditions' => ['keywords' => ['refund']],
            'actions' => ['reply' => 'A refund takes 5-7 days.'],
        ]);

        $this->automation('auto_reply', 'Catch all', [
            'priority' => 10,
            'conditions' => ['keywords' => []],
            'actions' => ['reply' => 'We will get back to you.'],
        ]);

        $this->inbound('where is my refund?');

        $messages = $this->outbound();

        $this->assertCount(1, $messages);
        $this->assertSame('A refund takes 5-7 days.', $messages[0]->body);
    }

    public function test_an_opted_out_contact_is_never_auto_replied_to(): void
    {
        $this->automation('auto_reply', 'Catch all', [
            'conditions' => ['keywords' => []],
            'actions' => ['reply' => 'Thanks for your message!'],
        ]);

        $this->inbound('STOP');

        $this->assertCount(0, $this->outbound());
    }

    // ---- Routing -----------------------------------------------------------

    public function test_a_routing_rule_assigns_a_new_conversation_to_a_specific_person(): void
    {
        $this->automation('routing', 'Agent only', [
            'conditions' => [],
            'actions' => ['strategy' => 'specific_user', 'user_id' => $this->agent->id],
        ]);

        $this->inbound('I need help');

        $conversation = Tenancy::run($this->workspace, fn () => Conversation::first());

        $this->assertSame($this->agent->id, $conversation->assigned_user_id);

        $logged = Tenancy::run($this->workspace, fn () => ActivityLog::query()
            ->where('contact_id', $conversation->contact_id)
            ->where('description', 'like', '%Agent only%')
            ->count());

        $this->assertSame(1, $logged);
    }

    public function test_a_round_robin_rule_spreads_new_conversations_across_its_pool(): void
    {
        $this->automation('routing', 'Sales pool', [
            'conditions' => [],
            'actions' => ['strategy' => 'round_robin', 'user_ids' => [$this->admin->id, $this->agent->id]],
        ]);

        $this->inbound('first', '919000000011');
        $this->inbound('second', '919000000012');

        $assignees = Tenancy::run($this->workspace, fn () => Conversation::query()
            ->orderBy('id')
            ->pluck('assigned_user_id')
            ->all());

        $this->assertCount(2, $assignees);
        $this->assertContains($assignees[0], [$this->admin->id, $this->agent->id]);
        $this->assertContains($assignees[1], [$this->admin->id, $this->agent->id]);
        $this->assertNotSame($assignees[0], $assignees[1], 'Round-robin must alternate.');
    }

    public function test_a_routing_rule_ignores_a_user_from_another_workspace(): void
    {
        $outsider = User::factory()->create(['name' => 'Outsider']);

        $this->automation('routing', 'Bad pool', [
            'conditions' => [],
            'actions' => ['strategy' => 'specific_user', 'user_id' => $outsider->id],
        ]);

        $this->inbound('hello');

        $conversation = Tenancy::run($this->workspace, fn () => Conversation::first());

        $this->assertNotSame($outsider->id, $conversation->assigned_user_id);
    }

    // ---- Follow-up sequences ----------------------------------------------

    /**
     * A conversation whose last message was ours and where the customer has been
     * quiet for two hours.
     */
    private function quietThread(Contact $contact, int $silentHours = 2): Conversation
    {
        $conversation = $this->conversation($contact);

        Tenancy::run($this->workspace, function () use ($conversation, $silentHours) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'sender_type' => 'user',
                'type' => 'text',
                'body' => 'Here is our catalogue.',
                'status' => 'sent',
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ]);

            $conversation->forceFill([
                'last_message_at' => now()->subHours($silentHours),
                'last_inbound_at' => now()->subHours($silentHours + 1),
            ])->save();
        });

        return $conversation;
    }

    public function test_a_sequence_enrols_a_quiet_thread_and_sends_its_first_step_immediately(): void
    {
        $automation = $this->automation('sequence', 'Nudge', [
            'conditions' => ['no_reply_after_hours' => 1],
        ]);
        $this->step($automation, 0, 0);
        $this->step($automation, 1, 60);

        $conversation = $this->quietThread($this->contact());

        $this->artisan('automations:process')->assertSuccessful();

        $messages = $this->outbound();

        // The original outbound plus the first step.
        $this->assertCount(2, $messages);
        $this->assertSame('template', $messages[1]->type);
        $this->assertStringContainsString('Ananya', $messages[1]->body);

        $run = AutomationRun::where('automation_id', $automation->id)->firstOrFail();
        $this->assertSame('active', $run->status);
        $this->assertSame(1, $run->current_step);
        $this->assertSame($conversation->id, $run->conversation_id);
        $this->assertNotNull($run->next_run_at);
    }

    public function test_a_sequence_does_not_send_a_step_before_its_delay(): void
    {
        $automation = $this->automation('sequence', 'Slow nudge', [
            'conditions' => ['no_reply_after_hours' => 1],
        ]);
        $this->step($automation, 0, 120);

        $this->quietThread($this->contact());

        $this->artisan('automations:process')->assertSuccessful();

        $this->assertCount(1, $this->outbound()); // just the original
        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->firstOrFail()->current_step);
    }

    public function test_a_reply_stops_the_sequence(): void
    {
        $automation = $this->automation('sequence', 'Nudge', [
            'conditions' => ['no_reply_after_hours' => 1],
        ]);
        $this->step($automation, 0, 0);
        $this->step($automation, 1, 60);

        $conversation = $this->quietThread($this->contact());

        $this->artisan('automations:process')->assertSuccessful();
        $this->assertCount(2, $this->outbound());

        // The customer writes back, and the next step comes due.
        Tenancy::run($this->workspace, function () use ($conversation) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'sender_type' => 'contact',
                'type' => 'text',
                'body' => 'Sorry, just saw this!',
                'status' => 'delivered',
                'created_at' => now()->addMinutes(5),
                'updated_at' => now()->addMinutes(5),
            ]);
        });

        AutomationRun::where('automation_id', $automation->id)->update(['next_run_at' => now()->subMinute()]);

        $this->artisan('automations:process')->assertSuccessful();

        $this->assertCount(2, $this->outbound(), 'The second step must not be sent.');
        $this->assertSame('cancelled', AutomationRun::where('automation_id', $automation->id)->firstOrFail()->status);
    }

    public function test_a_sequence_never_enrols_an_opted_out_contact(): void
    {
        $automation = $this->automation('sequence', 'Nudge', [
            'conditions' => ['no_reply_after_hours' => 1],
        ]);
        $this->step($automation, 0, 0);

        $this->quietThread($this->contact(['is_opted_in' => false]));

        $this->artisan('automations:process')->assertSuccessful();

        $this->assertSame(0, AutomationRun::count());
        $this->assertCount(1, $this->outbound());
    }

    public function test_a_sequence_stops_when_its_template_is_no_longer_approved(): void
    {
        $automation = $this->automation('sequence', 'Nudge', [
            'conditions' => ['no_reply_after_hours' => 1],
        ]);
        $step = $this->step($automation, 0, 0);

        Tenancy::run($this->workspace, fn () => $step->template->update(['status' => 'rejected']));

        $this->quietThread($this->contact());

        $this->artisan('automations:process')->assertSuccessful();

        $run = AutomationRun::where('automation_id', $automation->id)->firstOrFail();

        $this->assertSame('cancelled', $run->status);
        $this->assertCount(1, $this->outbound());
    }

    // ---- The screen --------------------------------------------------------

    public function test_an_auto_reply_can_be_created_from_the_screen(): void
    {
        $this->actingAs($this->owner)->post('/w/acme/automations', [
            'type' => 'auto_reply',
            'name' => 'Pricing questions',
            'priority' => 5,
            'channels' => ['whatsapp', 'not-a-channel'],
            'keywords' => "price, cost\nprice",
            'outsideBusinessHours' => true,
            'reply' => 'Our plans start at ₹499.',
        ])->assertRedirect();

        $automation = Tenancy::run($this->workspace, fn () => Automation::firstOrFail());

        $this->assertSame('auto_reply', $automation->type);
        $this->assertSame($this->owner->id, $automation->created_by);
        $this->assertSame(['price', 'cost'], $automation->conditions['keywords']);
        $this->assertSame(['whatsapp'], $automation->conditions['channels']);
        $this->assertTrue($automation->conditions['outside_business_hours']);
        $this->assertSame('Our plans start at ₹499.', $automation->actions['reply']);
    }

    public function test_a_sequence_cannot_use_a_template_from_another_workspace(): void
    {
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->owner->id]);

        $foreign = Tenancy::runUnscoped(fn () => MessageTemplate::create([
            'workspace_id' => $other->id,
            'channel_account_id' => $this->account->id,
            'name' => 'someone-elses', 'language' => 'en', 'category' => 'marketing', 'status' => 'approved',
            'components' => [['type' => 'BODY', 'text' => 'Hi']],
        ]));

        $this->actingAs($this->owner)->post('/w/acme/automations', [
            'type' => 'sequence',
            'name' => 'Sneaky',
            'noReplyAfterHours' => 24,
            'steps' => [['delayMinutes' => 0, 'templateId' => $foreign->id, 'variables' => []]],
        ])->assertSessionHasErrors('steps.0.templateId');

        $this->assertSame(0, Tenancy::run($this->workspace, fn () => Automation::count()));
    }

    public function test_a_sequence_needs_at_least_one_step(): void
    {
        $this->actingAs($this->owner)->post('/w/acme/automations', [
            'type' => 'sequence',
            'name' => 'Empty',
            'noReplyAfterHours' => 24,
            'steps' => [],
        ])->assertSessionHasErrors('steps');

        $this->assertSame(0, Tenancy::run($this->workspace, fn () => Automation::count()));
    }

    public function test_a_rule_can_be_paused_resumed_and_deleted(): void
    {
        $automation = $this->automation('auto_reply', 'Pricing', ['conditions' => [], 'actions' => ['reply' => 'Hi']]);

        $this->actingAs($this->owner)
            ->post("/w/acme/automations/{$automation->id}/toggle")
            ->assertRedirect();
        $this->assertSame('paused', $automation->refresh()->status);

        // A paused rule cannot fire.
        $this->inbound('price?');
        $this->assertCount(0, $this->outbound());

        $this->actingAs($this->owner)
            ->post("/w/acme/automations/{$automation->id}/toggle")
            ->assertRedirect();
        $this->assertSame('active', $automation->refresh()->status);

        $this->actingAs($this->owner)
            ->delete("/w/acme/automations/{$automation->id}")
            ->assertRedirect();
        $this->assertSame(0, Tenancy::run($this->workspace, fn () => Automation::count()));
    }

    public function test_another_workspaces_automation_is_not_reachable(): void
    {
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->owner->id]);

        $foreign = Tenancy::runUnscoped(fn () => Automation::create([
            'workspace_id' => $other->id,
            'name' => 'Theirs', 'type' => 'auto_reply', 'status' => 'active',
            'conditions' => [], 'actions' => ['reply' => 'hi'],
        ]));

        $this->actingAs($this->owner)
            ->delete("/w/acme/automations/{$foreign->id}")
            ->assertNotFound();

        $this->assertSame(1, Automation::withoutGlobalScopes()->count());
    }

    public function test_the_automations_screen_renders(): void
    {
        $this->automation('auto_reply', 'Pricing', ['conditions' => ['keywords' => ['price']], 'actions' => ['reply' => 'Hi']]);

        $this->actingAs($this->owner)
            ->get('/w/acme/automations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Automations')
                ->has('automations', 1)
                ->has('businessHours.timezone')
                ->has('options.users', 3)
                ->has('options.templates', 1)
            );
    }

    public function test_business_hours_are_saved_on_the_workspace(): void
    {
        $this->actingAs($this->owner)->post('/w/acme/automations/business-hours', [
            'timezone' => 'Asia/Dubai',
            'days' => [1, 2, 3],
            'start' => '09:30',
            'end' => '18:00',
        ])->assertRedirect();

        $this->assertSame([
            'timezone' => 'Asia/Dubai',
            'days' => [1, 2, 3],
            'start' => '09:30',
            'end' => '18:00',
        ], $this->workspace->refresh()->settings['automation_business_hours']);
    }
}
