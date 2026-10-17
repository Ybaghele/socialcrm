<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Models\ChannelAccount;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Optional demo data for the Campaigns screen.
 *
 *   php artisan db:seed --class=DemoCampaignSeeder
 *
 * Seeds three live funnels for the "acme" workspace (or the first one) with the
 * same shape the automation engine produces, so the campaigns dashboard has
 * something real to render. Safe to re-run: it no-ops if campaigns already exist.
 */
class DemoCampaignSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::where('slug', 'acme')->first() ?? Workspace::first();

        if (! $workspace) {
            $this->command?->warn('No workspace found — nothing to seed.');

            return;
        }

        Tenancy::run($workspace, function () use ($workspace) {
            if (Campaign::query()->exists()) {
                $this->command?->info('Campaigns already exist for this workspace — skipping.');

                return;
            }

            $account = fn (string $channel) => ChannelAccount::query()->where('channel', $channel)->value('id');

            $campaigns = [
                [
                    'name' => 'Monsoon Restock Broadcast',
                    'description' => 'Sends a pre-approved WhatsApp template to your opted-in audience.',
                    'type' => 'broadcast',
                    'channel' => 'whatsapp',
                    'status' => 'active',
                    'message_body' => 'Hey {{name}}, our monsoon restock just landed — 20% off this week only.',
                    'audience_description' => ['label' => 'All opted-in subscribers'],
                    'delivered_count' => 6200,
                    'replied_count' => 2400,
                    'won_count' => 210,
                    'cost_paise' => 180000,
                ],
                [
                    'name' => 'Instagram Reel Price-Drop Funnel',
                    'description' => 'Auto-DMs anyone who comments a keyword on your Reels & Feed posts.',
                    'type' => 'comment_to_dm',
                    'channel' => 'instagram',
                    'status' => 'active',
                    'message_body' => 'Hey {{name}}, here is the price drop you asked about — grab it before it sells out.',
                    'audience_description' => ['label' => 'All Reels & Feed Posts'],
                    'delivered_count' => 5400,
                    'replied_count' => 2450,
                    'won_count' => 980,
                    'cost_paise' => 198420,
                ],
                [
                    'name' => 'Messenger Ad Click Welcome Bot',
                    'description' => 'Greets people the moment they tap your Click-to-Message ad.',
                    'type' => 'ad_click_welcome',
                    'channel' => 'messenger',
                    'status' => 'active',
                    'message_body' => 'Welcome! Thanks for tapping our ad — tell us what you are shopping for and we will help.',
                    'audience_description' => ['label' => 'Ad click-to-Message'],
                    'delivered_count' => 3290,
                    'replied_count' => 1523,
                    'won_count' => 230,
                    'cost_paise' => 120000,
                ],
            ];

            foreach ($campaigns as $attributes) {
                $campaign = Campaign::create([
                    ...$attributes,
                    'channel_account_id' => $account($attributes['channel']),
                    'message_template_id' => null,
                    'started_at' => now()->subDays(9),
                    'created_by' => $workspace->owner_id,
                ]);

                if ($attributes['type'] === 'comment_to_dm') {
                    CampaignTrigger::create([
                        'campaign_id' => $campaign->id,
                        'type' => 'comment_keyword',
                        'match_type' => 'contains',
                        'keywords' => ['PRICE', 'LINK', 'BUY'],
                        'scope' => ['all_posts' => true],
                    ]);
                }
            }

            $this->command?->info('Seeded 3 demo campaigns for workspace "'.$workspace->slug.'".');
        });
    }
}
