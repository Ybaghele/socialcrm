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
                    } elseif (!empty($bodyText) && !is_array($bodyText[0])) {
                        // Flat array of values - wrap it
                        $component['example']['body_text'] = [$bodyText];
                    }
                    // If it's already array of arrays, keep as-is
                }
            } elseif ($type === 'HEADER' && isset($component['example']['header_text'])) {
                $headerText = $component['example']['header_text'];
                if (is_array($headerText) && !empty($headerText) && isset($headerText[0]) && !is_array($headerText[0])) {
                    // For header text with single example, keep as array of strings
                } elseif (is_array($headerText) && !empty($headerText) && isset($headerText[0]) && is_array($headerText[0])) {
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
            'allow_category_change' => true,
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
            'payload_body' => $body
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
