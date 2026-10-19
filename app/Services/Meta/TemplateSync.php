<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use App\Models\Workspace;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pulls a tenant's WhatsApp message templates out of Meta and mirrors them into
 * `message_templates`.
 *
 * Meta is the source of truth for templates: they are authored in Business
 * Manager and approved (or rejected) there, so the app must never invent one.
 * Syncing is what makes an approved template show up in the campaign picker, and
 * what flips a template from "pending" to "approved" once Meta reviews it.
 */
class TemplateSync
{
    /**
     * @return array{imported: int, updated: int, scanned: int, errors: array<int, string>}
     */
    public function sync(Workspace $workspace): array
    {
        $imported = 0;
        $updated = 0;
        $scanned = 0;
        $errors = [];

        $accounts = ChannelAccount::query()
            ->where('channel', 'whatsapp')
            ->where('status', 'connected')
            ->get();

        foreach ($accounts as $account) {
            if (! $account->business_account_id) {
                $errors[] = "{$account->name}: no WhatsApp Business Account id saved for this number.";

                continue;
            }

            $token = $account->access_token;
            if (! $token) {
                $errors[] = "{$account->name}: no access token saved for this number.";

                continue;
            }

            try {
                $templates = $this->fetch($account->business_account_id, $token);
            } catch (Throwable $e) {
                $errors[] = "{$account->name}: ".$this->reason($e);

                continue;
            }

            foreach ($templates as $template) {
                $scanned++;

                $existing = MessageTemplate::query()
                    ->where('channel_account_id', $account->id)
                    ->where('name', $template['name'])
                    ->where('language', $template['language'])
                    ->first();

                $attributes = [
                    'channel_account_id' => $account->id,
                    'name' => $template['name'],
                    'language' => $template['language'],
                    'category' => $template['category'],
                    'status' => $template['status'],
                    'meta_template_id' => $template['id'],
                    'rejection_reason' => $template['rejected_reason'],
                    'components' => $template['components'],
                ];

                if ($existing) {
                    $existing->update($attributes);
                    $updated++;
                } else {
                    MessageTemplate::create($attributes);
                    $imported++;
                }
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'scanned' => $scanned, 'errors' => $errors];
    }

    /**
     * @return array<int, array{id: string, name: string, language: string, category: string, status: string, components: array, rejected_reason: ?string}>
     */
    private function fetch(string $wabaId, string $token): array
    {
        $base = rtrim((string) config('omniflow.meta.hosts.whatsapp', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('omniflow.meta.graph_version'), '/');

        $response = Http::withToken($token)
            ->timeout(30)
            ->get("{$base}/{$version}/{$wabaId}/message_templates", [
                'fields' => 'id,name,status,category,language,components,rejected_reason',
                'limit' => 200,
            ])
            ->throw();

        return collect($response->json('data', []))
            ->map(fn (array $template) => [
                'id' => (string) ($template['id'] ?? ''),
                'name' => (string) ($template['name'] ?? ''),
                'language' => (string) ($template['language'] ?? 'en'),
                'category' => strtolower((string) ($template['category'] ?? 'marketing')),
                // Meta returns APPROVED / PENDING / REJECTED / PAUSED.
                'status' => strtolower((string) ($template['status'] ?? 'pending')),
                'components' => is_array($template['components'] ?? null) ? $template['components'] : [],
                'rejected_reason' => $template['rejected_reason'] ?? null,
            ])
            ->filter(fn (array $template) => $template['name'] !== '')
            ->values()
            ->all();
    }

    private function reason(Throwable $e): string
    {
        // Graph errors arrive as a JSON body on the HTTP exception; surface the
        // human-readable message instead of a wall of response headers.
        if ($e instanceof RequestException) {
            $message = $e->response->json('error.message');

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return $e->getMessage();
    }
}
