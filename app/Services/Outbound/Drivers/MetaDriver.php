<?php

namespace App\Services\Outbound\Drivers;

use App\Models\ChannelAccount;
use App\Services\Outbound\ChannelDriver;
use App\Services\Outbound\SendFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Shared HTTP + error mapping for every Meta Graph messaging endpoint. */
abstract class MetaDriver implements ChannelDriver
{
    // Meta error codes that mean "slow down / try again", not "this message is bad".
    private const RETRYABLE_CODES = [80007, 130429, 131056];

    protected function post(ChannelAccount $account, string $path, array $payload): Response
    {
        $host = config("omniflow.meta.hosts.{$account->channel}", 'https://graph.facebook.com');
        $url = rtrim($host, '/').'/'.config('omniflow.meta.graph_version').'/'.ltrim($path, '/');

        try {
            $response = Http::withToken((string) ChannelAccount::normaliseToken($account->access_token))->acceptJson()->timeout(15)->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new SendFailed('Could not reach Meta: '.$e->getMessage(), retryable: true);
        }

        if ($response->successful()) {
            return $response;
        }

        $code = $response->json('error.code');
        $message = $response->json('error.message') ?: 'HTTP '.$response->status();

        // 190 = token expired/revoked: flag the account so the UI can ask the tenant to reconnect.
        if ((int) $code === 190) {
            $account->update(['status' => 'error']);
        }

        $retryable = $response->status() === 429 || $response->status() >= 500 || in_array((int) $code, self::RETRYABLE_CODES, true);

        throw new SendFailed($message, $retryable, $code !== null ? (string) $code : 'http_'.$response->status());
    }
}
