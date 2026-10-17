<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Inbound\InboundMessageProcessor;
use App\Services\Inbound\MetaPayloadParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Global (not tenant-aware) on purpose: it discovers the tenant from the payload.
 * Everything it writes happens inside InboundMessageProcessor under Tenancy::run().
 * Safe to retry: message ingestion is idempotent on the provider message id.
 */
class ProcessMetaWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $webhookEventId)
    {
    }

    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(MetaPayloadParser $parser, InboundMessageProcessor $processor): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->status === 'processed') {
            return;
        }

        try {
            $parsed = $parser->parse($event->payload);
            $handled = 0;

            foreach ($parsed['messages'] as $message) {
                $processor->handle($message) && $handled++;
            }
            foreach ($parsed['statuses'] as $status) {
                $processor->applyStatus($status) && $handled++;
            }

            $event->update([
                'status' => $handled > 0 ? 'processed' : 'ignored',
                'processed_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            $event->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000)]);

            throw $e;
        }
    }
}
