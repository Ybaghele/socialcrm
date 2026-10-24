<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TemplateCreator
{
    /**
     * @return array{id: string, status: string}
     */
    public function create(ChannelAccount $account, array $payload): array
    {
        if (! $account->business_account_id) {
            throw new \InvalidArgumentException('No WhatsApp Business Account ID configured for this channel.');
        }

        if (! $account->access_token) {
            throw new \InvalidArgumentException('No access token configured for this channel.');
        }

        $base = rtrim((string) config('omniflow.meta.hosts.whatsapp', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('omniflow.meta.graph_version'), '/');

        $body = [
            'name' => $payload['name'],
            'category' => $payload['category'],
            'language' => $payload['language'],
            'components' => $payload['components'],
            'allow_category_change' => true,
        ];

        $response = Http::withToken($account->access_token)
            ->timeout(30)
            ->post("{$base}/{$version}/{$account->business_account_id}/message_templates", $body)
            ->throw();

        return [
            'id' => (string) ($response->json('id') ?? ''),
            'status' => strtolower((string) ($response->json('status') ?? 'pending')),
        ];
    }

    public function reason(Throwable $e): string
    {
        if ($e instanceof RequestException && $e->response) {
            $message = $e->response->json('error.message');

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return Str::limit($e->getMessage(), 200);
    }
}
