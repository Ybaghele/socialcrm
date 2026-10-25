<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TemplateCreator
{
    /**
     * @return array{id: string, status: string}
     */
    // public function create(ChannelAccount $account, array $payload): array
    // {
    //     if (! $account->business_account_id) {
    //         throw new \InvalidArgumentException('No WhatsApp Business Account ID configured for this channel.');
    //     }

    //     if (! $account->access_token) {
    //         throw new \InvalidArgumentException('No access token configured for this channel.');
    //     }

    //     $base = rtrim((string) config('omniflow.meta.hosts.whatsapp', 'https://graph.facebook.com'), '/');
    //     $version = trim((string) config('omniflow.meta.graph_version'), '/');

    //     $body = [
    //         'name' => $payload['name'],
    //         'category' => $payload['category'],
    //         'language' => $payload['language'],
    //         'components' => $payload['components'],
    //         'allow_category_change' => true,
    //     ];

    //     $response = Http::withToken($account->access_token)
    //         ->timeout(30)
    //         ->post("{$base}/{$version}/{$account->business_account_id}/message_templates", $body)
    //         ->throw();

    //     return [
    //         'id' => (string) ($response->json('id') ?? ''),
    //         'status' => strtolower((string) ($response->json('status') ?? 'pending')),
    //     ];
    // }

    public function create(ChannelAccount $account, array $payload): array
    {
        // 1. Log the incoming transmission details immediately to trace execution entry
        Log::info('WhatsApp Template Creation Triggered', [
            'account_id' => $account->id ?? 'N/A',
            'business_account_id' => $account->business_account_id ?? 'Missing',
            'has_token' => ! empty($account->access_token),
        ]);

        if (! $account->business_account_id) {
            Log::error('Template creation halted: Missing Business Account ID');
            throw new \InvalidArgumentException('No WhatsApp Business Account ID configured for this channel.');
        }

        if (! $account->access_token) {
            Log::error('Template creation halted: Missing Access Token');
            throw new \InvalidArgumentException('No access token configured for this channel.');
        }

        // Make sure this points to the right config or falls back safely
        $base = rtrim((string) config('omniflow.meta.hosts.whatsapp', 'https://graph.facebook.com'), '/');
        if ($base === 'https://' || $base === 'http://') {
            $base = 'https://graph.facebook.com';
        }
        $version = trim((string) config('omniflow.meta.graph_version', config('omniflow.meta.graph_version', 'v22.0')), '/');
        $url = "{$base}/{$version}/{$account->business_account_id}/message_templates";

        $components = $payload['components'] ?? [];

        // Normalize component examples to Meta's expected format
        foreach ($components as &$component) {
            $type = strtoupper($component['type'] ?? '');
            if ($type === 'BODY' && isset($component['example']['body_text'])) {
                $bodyText = $component['example']['body_text'];
                // Meta expects body_text as array of arrays: [["value1", "value2"]]
                if (is_array($bodyText)) {
                    if (empty($bodyText)) {
                        unset($component['example']);
                    } elseif (! empty($bodyText) && ! is_array($bodyText[0])) {
                        // Flat array of values - wrap it
                        $component['example']['body_text'] = [$bodyText];
                    }
                    // If it's already array of arrays, keep as-is
                }
            } elseif ($type === 'HEADER' && isset($component['example']['header_text'])) {
                $headerText = $component['example']['header_text'];
                if (is_array($headerText) && ! empty($headerText) && isset($headerText[0]) && ! is_array($headerText[0])) {
                    // For header text with single example, keep as array of strings
                } elseif (is_array($headerText) && ! empty($headerText) && isset($headerText[0]) && is_array($headerText[0])) {
                    // Flatten if nested
                    $component['example']['header_text'] = $headerText[0];
                }
            }
        }
        unset($component);

        $name = strtolower(str_replace(' ', '_', (string) ($payload['name'] ?? '')));
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        $name = preg_replace('/_{2,}/', '_', $name);
        $name = trim($name, '_');

        $body = [
            'name' => $name,
            'category' => strtoupper((string) ($payload['category'] ?? 'MARKETING')),
            'language' => (string) ($payload['language'] ?? 'en_US'),
            'components' => $components,
        ];

        $appSecret = config('services.meta.app_secret') ?? config('omniflow.meta.app_secret') ?? env('META_APP_SECRET');
        $token = ChannelAccount::normaliseToken($account->access_token);
        if (is_string($appSecret) && $appSecret !== '' && $token) {
            $body['appsecret_proof'] = hash_hmac('sha256', $token, $appSecret);
        }

        Log::info('TEMPLATE_PAYLOAD: '.json_encode($body));

        // Log the exact JSON payload being transmitted to spot formatting bugs
        Log::info('Sending Request Payload to Meta API Gateway', [
            'target_url' => $url,
            'payload_body' => $body,
        ]);

        try {
            $token = ChannelAccount::normaliseToken($account->access_token);
            // Execute request without chaining ->throw() to bypass cryptographic crash limits
            $response = Http::withToken($token)
                ->timeout(30)
                ->post($url, $body);

            if (! $response->successful()) {
                Log::error($response->body());
            }

            // 2. Log the raw response parameters immediately
            Log::info('Meta API Response Received Status Code: '.$response->status(), [
                'raw_body' => $response->body(),
                'json_data' => $response->json(),
            ]);

            if ($response->failed()) {
                Log::error('Meta Core Gateway Rejected Template JSON Payload', [
                    'status' => $response->status(),
                    'error_response' => $response->json(),
                ]);

                return [
                    'id' => '',
                    'status' => 'failed',
                    'error' => $response->json(),
                ];
            }

            return [
                'id' => (string) ($response->json('id') ?? ''),
                'status' => strtolower((string) ($response->json('status') ?? 'pending')),
            ];

        } catch (Throwable $e) {
            // 3. Capture severe network or internal framework serialization errors
            Log::critical('Fatal System Exception Caught During Template Generation Pipeline', [
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Upload a template sample image through Meta's Resumable Upload API.
     *
     * Template media headers do not accept an arbitrary public URL at creation
     * time: Meta wants an asset handle produced by an upload session. Campaign
     * sends, by contrast, do accept a public URL, which is why the sample and the
     * per-campaign image are stored separately.
     */
    public function uploadImage(ChannelAccount $account, string $path, string $mimeType, string $fileName): string
    {
        $appId = trim((string) config('omniflow.meta.app_id'));

        if ($appId === '') {
            throw new \InvalidArgumentException('Set META_APP_ID before submitting a template with an image header.');
        }

        if (! $account->access_token) {
            throw new \InvalidArgumentException('No access token configured for this channel.');
        }

        if (! is_file($path)) {
            throw new \InvalidArgumentException('The uploaded image could not be read.');
        }

        $length = filesize($path);

        if ($length === false) {
            throw new \InvalidArgumentException('The uploaded image size could not be read.');
        }

        $base = rtrim((string) config('omniflow.meta.hosts.whatsapp', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('omniflow.meta.graph_version', 'v22.0'), '/');
        $token = ChannelAccount::normaliseToken($account->access_token);

        if (! $token) {
            throw new \InvalidArgumentException('No access token configured for this channel.');
        }

        $session = Http::withToken($token)
            ->timeout(30)
            ->post("{$base}/{$version}/{$appId}/uploads", [
                'file_name' => basename($fileName),
                'file_length' => $length,
                'file_type' => $mimeType,
            ])
            ->throw();

        $sessionId = trim((string) $session->json('id'));

        if ($sessionId === '') {
            throw new \RuntimeException('Meta did not return an upload session for the image.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \InvalidArgumentException('The uploaded image could not be read.');
        }

        // The session id carries its own `?sig=...` query string, so it must be
        // used exactly as returned. Appending appsecret_proof here would corrupt
        // that signature and Meta would reject the upload with "HMAC check failed".
        $url = "{$base}/{$version}/{$sessionId}";

        // The upload endpoint expects the raw bytes and an explicit offset. It
        // also wants the `OAuth` prefix rather than a Bearer token.
        $response = Http::withHeaders([
            'Authorization' => 'OAuth '.$token,
            'file_offset' => '0',
        ])
            ->withBody($contents, $mimeType)
            ->timeout(60)
            ->post($url)
            ->throw();

        $handle = trim((string) $response->json('h'));

        if ($handle === '') {
            throw new \RuntimeException('Meta did not return an image handle.');
        }

        return $handle;
    }

    // public function reason(Throwable $e): string
    // {
    //     if ($e instanceof RequestException && $e->response) {
    //         $message = $e->response->json('error.message');

    //         if (is_string($message) && $message !== '') {
    //             return $message;
    //         }
    //     }

    //     return Str::limit($e->getMessage(), 200);
    // }
    public function reason(Throwable $e): string
    {
        // Check if the exception has a built-in response object (like RequestException)
        if (method_exists($e, 'hasResponse') && $e->hasResponse()) {
            $response = $e->response;
            // Prefer Meta's user-facing explanation: error.message is often just "Invalid parameter".
            $message = $response->json('error.error_user_msg')
                ?? $response->json('error.error_user_title')
                ?? $response->json('error.message')
                ?? $response->json('message');

            Log::info('Sending Request reason 1 to Meta API Gateway', [
                'response' => $response,
                'message' => $message,
            ]);
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        Log::info('Sending Request reason 2 to Meta API Gateway', [
            'message' => $e->getMessage(),
        ]);

        // Fall back to the default message string if no JSON context is available
        return Str::limit($e->getMessage(), 200);
    }
}
