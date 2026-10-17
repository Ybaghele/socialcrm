<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Jobs\ProcessMetaWebhook;
use App\Models\ChannelAccount;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Exercise the whole inbound pipeline without Meta: php artisan omniflow:simulate-inbound acme --sync */
class SimulateInboundMessage extends Command
{
    protected $signature = 'omniflow:simulate-inbound
        {workspace : Workspace slug}
        {--channel=whatsapp : whatsapp|instagram|messenger}
        {--from=919876543210 : Sender id / phone digits}
        {--name=Test Lead : Sender display name (WhatsApp only)}
        {--text=Hi, what is the price? : Message text}
        {--sync : Process immediately instead of queueing}';

    protected $description = 'Create a fake Meta webhook event and push it through the real inbound pipeline';

    public function handle(): int
    {
        $workspace = Workspace::where('slug', $this->argument('workspace'))->first();
        if (! $workspace) {
            $this->error('Workspace not found.');

            return self::FAILURE;
        }

        $channel = ChannelType::tryFrom((string) $this->option('channel'));
        if (! $channel) {
            $this->error('Channel must be whatsapp, instagram or messenger.');

            return self::FAILURE;
        }

        // external_id must be globally unique across tenants, so namespace it by slug.
        $account = Tenancy::run($workspace, fn () => ChannelAccount::firstOrCreate(
            ['channel' => $channel->value, 'external_id' => "sim-{$workspace->slug}-{$channel->value}"],
            ['name' => 'Simulated '.ucfirst($channel->value), 'status' => 'connected'],
        ));

        $payload = $this->payload($channel, $account->external_id);

        $event = WebhookEvent::create([
            'provider' => 'meta',
            'event_type' => $payload['object'],
            'external_id' => (string) Str::uuid(),
            'payload' => $payload,
            'status' => 'pending',
        ]);

        $this->option('sync') ? ProcessMetaWebhook::dispatchSync($event->id) : ProcessMetaWebhook::dispatch($event->id);

        $this->info(($this->option('sync') ? 'Processed' : 'Queued')." simulated {$channel->value} message for [{$workspace->slug}] (event #{$event->id}).");

        return self::SUCCESS;
    }

    private function payload(ChannelType $channel, string $accountId): array
    {
        $from = (string) $this->option('from');
        $text = (string) $this->option('text');
        $unique = Str::random(12);

        if ($channel === ChannelType::WhatsApp) {
            return [
                'object' => 'whatsapp_business_account',
                'entry' => [[
                    'id' => 'WABA_SIM',
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => ['display_phone_number' => '910000000000', 'phone_number_id' => $accountId],
                            'contacts' => [['profile' => ['name' => (string) $this->option('name')], 'wa_id' => $from]],
                            'messages' => [['from' => $from, 'id' => "wamid.SIM{$unique}", 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
                        ],
                    ]],
                ]],
            ];
        }

        return [
            'object' => $channel === ChannelType::Instagram ? 'instagram' : 'page',
            'entry' => [[
                'id' => $accountId,
                'time' => time() * 1000,
                'messaging' => [[
                    'sender' => ['id' => $from],
                    'recipient' => ['id' => $accountId],
                    'timestamp' => time() * 1000,
                    'message' => ['mid' => "mid.SIM{$unique}", 'text' => $text],
                ]],
            ]],
        ];
    }
}
