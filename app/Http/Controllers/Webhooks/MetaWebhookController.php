<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\ProcessMetaWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** One global endpoint for every tenant's WhatsApp/Instagram/Messenger traffic. */
class MetaWebhookController
{
    // GET: Meta's one-time subscription handshake. PHP rewrites "hub.mode" to "hub_mode".
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $expected = (string) config('omniflow.meta.verify_token');

        abort_unless($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token), 403);

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge')), 200)
            ->header('Content-Type', 'text/plain');
    }

    // POST: verify signature, persist raw payload, queue, return 200 fast (Meta retries slow/failed calls).
    public function receive(Request $request): Response
    {
        $secret = (string) config('omniflow.meta.app_secret');
        abort_if($secret === '', 500, 'META_APP_SECRET is not configured.');

        $raw = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256', '')), 403);

        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 400);

        // Identical retries of the same delivery collapse into one row.
        $event = WebhookEvent::firstOrCreate(
            ['provider' => 'meta', 'external_id' => hash('sha256', $raw)],
            ['event_type' => $payload['object'] ?? null, 'payload' => $payload, 'status' => 'pending'],
        );

        if ($event->wasRecentlyCreated || $event->status === 'failed') {
            ProcessMetaWebhook::dispatch($event->id);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
