<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
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
            'has_token' => !empty($account->access_token)
        ]);

        if (! $account->business_account_id) {
            Log::error('Template creation halted: Missing Business Account ID');
            throw new \InvalidArgumentException('No WhatsApp Business Account ID configured for this channel.');
        }

        if (! $account->access_token) {
            Log::error('Template creation halted: Missing Access Token');
            throw new \InvalidArgumentException('No access token configured for this channel.');
        }

        // Make sure this points to the right config or falls back safely to ://facebook.com
        $base = rtrim((string) config('omniflow.meta.hosts.whatsapp', 'https://://facebook.com'), '/');
        $version = trim((string) config('omniflow.meta.graph_version', 'v21.0'), '/');
        $url = "{$base}/{$version}/{$account->business_account_id}/message_templates";

        $body = [
            'name' => strtolower((string) ($payload['name'] ?? '')),
            'category' => strtoupper((string) ($payload['category'] ?? 'MARKETING')),
            'language' => (string) ($payload['language'] ?? 'en_US'),
            'components' => $payload['components'] ?? [],
            'allow_category_change' => true,
        ];

        $appSecret = config('services.meta.app_secret') ?? config('omniflow.meta.app_secret') ?? env('META_APP_SECRET');
        if (is_string($appSecret) && $appSecret !== '') {
            $tokenForProof = $account->access_token ? ChannelAccount::normaliseToken($account->access_token) : null;
            if ($tokenForProof) {
                $body['appsecret_proof'] = hash_hmac('sha256', $tokenForProof, $appSecret);
            }
        }

        // Log the exact JSON payload being transmitted to spot formatting bugs
        Log::info('Sending Request Payload to Meta API Gateway', [
            'target_url' => $url,
            'payload_body' => $body
        ]);

        try {
            // Execute request without chaining ->throw() to bypass cryptographic crash limits
            $response = Http::withToken($account->access_token)
                ->timeout(30)
                ->post($url, $body);

            // 2. Log the raw response parameters immediately
            Log::info('Meta API Response Received Status Code: ' . $response->status(), [
                'raw_body' => $response->body(),
                'json_data' => $response->json()
            ]);

            if ($response->failed()) {
                Log::error('Meta Core Gateway Rejected Template JSON Payload', [
                    'status' => $response->status(),
                    'error_response' => $response->json()
                ]);
                
                return [
                    'id' => '',
                    'status' => 'failed',
                    'error' => $response->json()
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
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
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
            $message = $response->json('error.message') ?? $response->json('message');
            
            Log::info('Sending Request reason 1 to Meta API Gateway', [
                'response' => $response,
                'message' => $message
            ]);
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        Log::info('Sending Request reason 2 to Meta API Gateway', [
            'message' => $e->getMessage()
        ]);
        // Fall back to the default message string if no JSON context is available
        return Str::limit($e->getMessage(), 200);
    }

}
