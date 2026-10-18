<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WhatsApp Embedded Signup: the self-service half of onboarding.
 *
 * The customer clicks "Connect WhatsApp", Meta walks them through picking (or
 * creating) a WABA and a phone number, and we come back holding a business token
 * that already carries whatsapp_business_management + whatsapp_business_messaging.
 * The operator never has to copy a phone number ID by hand.
 *
 * Off until META_APP_ID + META_EMBEDDED_SIGNUP_CONFIG_ID are configured, which
 * needs Business Verification + App Review first (see DEPLOY_HOSTINGER.md 12).
 */
class MetaOAuth
{
    public function isEnabled(): bool
    {
        return filled(config('omniflow.meta.app_id'))
            && filled(config('omniflow.meta.embedded_signup_config_id'));
    }

    /** @return array{enabled: bool, connectUrl: string} */
    public function summary(string $workspaceSlug, bool $mayConnect): array
    {
        $enabled = $this->isEnabled();

        return [
            'enabled' => $enabled,
            // Only the owner may grant an asset to the workspace, and the button is
            // useless when the app is not wired up yet.
            'connectUrl' => $enabled && $mayConnect
                ? route('channels.meta.start', ['workspace' => $workspaceSlug])
                : null,
        ];
    }

    /**
     * The Embedded Signup dialog. `extras.featureType` is what makes Meta ask for
     * a WABA + number instead of a plain Facebook Login.
     */
    public function dialogUrl(string $redirectUri, string $state): string
    {
        $extras = json_encode([
            'display' => 'page',
            'featureType' => 'whatsapp_embedded_signup',
            'sessionInfoVersion' => '2',
        ], JSON_UNESCAPED_SLASHES);

        return $this->graphUrl('/dialog/oauth', [
            'client_id' => config('omniflow.meta.app_id'),
            'redirect_uri' => $redirectUri,
            'config_id' => config('omniflow.meta.embedded_signup_config_id'),
            'response_type' => 'code',
            'override_default_response_type' => 'true',
            'extras' => $extras,
            'state' => $state,
        ], host: 'https://www.facebook.com');
    }

    /** Short-lived business token. @throws ConnectionException */
    public function exchangeCode(string $code, string $redirectUri): string
    {
        $token = Http::asForm()->timeout(20)->post($this->graphUrl('/oauth/access_token'), [
            'client_id' => config('omniflow.meta.app_id'),
            'client_secret' => config('omniflow.meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ])->throw()->json('access_token');

        return (string) $token;
    }

    /**
     * Short-lived tokens die in about an hour; a system-user style long-lived one
     * lasts ~60 days, which is what a customer should keep.
     *
     * @return array{access_token: string, expires_in: ?int}
     */
    public function upgradeToken(string $shortLivedToken): array
    {
        $response = Http::asForm()->timeout(20)->get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('omniflow.meta.app_id'),
            'client_secret' => config('omniflow.meta.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ])->throw();

        return [
            'access_token' => (string) $response->json('access_token'),
            'expires_in' => $response->json('expires_in') === null ? null : (int) $response->json('expires_in'),
        ];
    }

    /**
     * Every WABA + phone number the customer granted us.
     *
     * @return array<int, array{id: string, name: string, phone_numbers: array<int, array{id: string, display_phone_number: ?string, verified_name: ?string}>}>
     */
    public function whatsappBusinessAccounts(string $token): array
    {
        $response = Http::withToken($token)->timeout(20)->get($this->graphUrl('/me/whatsapp_business_accounts'), [
            'fields' => 'id,name,phone_numbers{display_phone_number,verified_name}',
            'limit' => 50,
        ])->throw();

        return collect($response->json('data', []))->map(fn (array $waba) => [
            'id' => (string) ($waba['id'] ?? ''),
            'name' => (string) ($waba['name'] ?? ''),
            'phone_numbers' => collect(data_get($waba, 'phone_numbers.data', []))
                ->map(fn (array $phone) => [
                    'id' => (string) ($phone['id'] ?? ''),
                    'display_phone_number' => $phone['display_phone_number'] ?? null,
                    'verified_name' => $phone['verified_name'] ?? null,
                ])
                ->filter(fn (array $phone) => $phone['id'] !== '')
                ->values()
                ->all(),
        ])->filter(fn (array $waba) => $waba['id'] !== '')->values()->all();
    }

    /**
     * Point the app's global webhook at this WABA, otherwise messages for the
     * number we just connected never reach /webhooks/meta.
     */
    public function subscribeApp(string $token, string $wabaId): bool
    {
        return Http::asForm()
            ->withToken($token)
            ->timeout(20)
            ->post($this->graphUrl('/'.$wabaId.'/subscribed_apps'), [
                'app_ids' => (string) config('omniflow.meta.app_id'),
            ])->successful();
    }

    /**
     * `state` binds a callback to the workspace it came from so a crafted link
     * cannot drop a token into someone else's tenant. The user still has to be an
     * authorised owner of that workspace; this is CSRF protection, not authz.
     */
    public function state(string $workspaceSlug, int $userId): string
    {
        $payload = $workspaceSlug.'.'.$userId;

        return $payload.'.'.Str::substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 16);
    }

    /** @return array{workspace: string, user_id: int}|null */
    public function parseState(?string $state): ?array
    {
        if (! is_string($state)) {
            return null;
        }

        $parts = explode('.', $state);

        if (count($parts) !== 3) {
            return null;
        }

        [$slug, $userId, $signature] = $parts;

        if (! ctype_digit($userId)) {
            return null;
        }

        $expected = Str::substr(hash_hmac('sha256', $slug.'.'.$userId, (string) config('app.key')), 0, 16);

        return hash_equals($expected, $signature)
            ? ['workspace' => $slug, 'user_id' => (int) $userId]
            : null;
    }

    private function graphUrl(string $path, array $query = [], ?string $host = null): string
    {
        $base = rtrim((string) ($host ?? config('omniflow.meta.hosts.whatsapp', 'https://graph.facebook.com')), '/');

        return $base.'/'.trim((string) config('omniflow.meta.graph_version'), '/').$path.($query === [] ? '' : '?'.http_build_query($query));
    }
}
