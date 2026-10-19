<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Meta\TemplateSync;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Templates are authored + approved in Meta, so syncing mirrors their status
 * (including the APPROVED → REJECTED flip) without ever duplicating a row.
 */
class TemplateSyncTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['omniflow.meta.graph_version' => 'v22.0']);

        $owner = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $owner->id]);
        $this->workspace->users()->attach($owner->id, ['role' => 'owner']);

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

    private function sync(): array
    {
        return Tenancy::run($this->workspace, fn () => app(TemplateSync::class)->sync($this->workspace));
    }

    public function test_it_mirrors_meta_templates_with_lowercased_status(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates*' => Http::response(['data' => [
            [
                'id' => '100', 'name' => 'diwali_festive_offer_2026', 'status' => 'APPROVED',
                'category' => 'MARKETING', 'language' => 'en',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, festive offer!']],
            ],
            [
                'id' => '101', 'name' => 'shipping_update', 'status' => 'PENDING',
                'category' => 'UTILITY', 'language' => 'en',
                'components' => [['type' => 'BODY', 'text' => 'Your order shipped']],
            ],
        ]], 200)]);

        $result = $this->sync();

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['errors']);

        Tenancy::run($this->workspace, function () {
            $approved = MessageTemplate::where('name', 'diwali_festive_offer_2026')->first();
            $this->assertSame('approved', $approved->status);
            $this->assertSame('marketing', $approved->category);
            $this->assertSame('100', $approved->meta_template_id);
            $this->assertSame(1, $approved->placeholderCount());

            $this->assertSame('pending', MessageTemplate::where('name', 'shipping_update')->value('status'));
        });
    }

    public function test_resyncing_updates_in_place_without_duplicating(): void
    {
        Http::fakeSequence('graph.facebook.com/*/message_templates*')
            ->push(['data' => [
                ['id' => '100', 'name' => 'diwali_festive_offer_2026', 'status' => 'PENDING', 'category' => 'MARKETING', 'language' => 'en', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']]],
            ]], 200)
            ->push(['data' => [
                ['id' => '100', 'name' => 'diwali_festive_offer_2026', 'status' => 'REJECTED', 'category' => 'MARKETING', 'language' => 'en', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']], 'rejected_reason' => 'Promotional content'],
            ]], 200);

        $this->sync();
        $result = $this->sync();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['updated']);

        Tenancy::run($this->workspace, function () {
            $this->assertSame(1, MessageTemplate::count());
            $template = MessageTemplate::first();
            $this->assertSame('rejected', $template->status);
            $this->assertSame('Promotional content', $template->rejection_reason);
        });
    }

    public function test_a_graph_error_is_reported_not_thrown(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token'],
        ], 400)]);

        $result = $this->sync();

        $this->assertSame(0, $result['scanned']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Invalid OAuth access token', $result['errors'][0]);
    }
}
