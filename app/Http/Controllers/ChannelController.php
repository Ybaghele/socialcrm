<?php

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Models\ChannelAccount;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Services\Meta\MetaOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET    /w/{workspace}/channels                          connected Meta assets + webhook setup
 * POST   /w/{workspace}/channels                          connect a WhatsApp number / IG account / Page
 * PATCH  /w/{workspace}/channels/{channelAccount}         rename, refresh the token, connect/disconnect
 * POST   /w/{workspace}/channels/{channelAccount}/verify  ask Meta whether the stored credentials work
 * DELETE /w/{workspace}/channels/{channelAccount}         remove an account that has no history yet
 *
 * ONE platform-level Meta app serves every tenant, so the webhook is global: it
 * resolves the tenant from channel_accounts.external_id (see InboundMessageProcessor).
 * That makes this screen about registering each tenant's asset ids + tokens against
 * that shared app, not about per-tenant OAuth. Swapping these forms for WhatsApp
 * Embedded Signup / Facebook Login later only changes where the values come from.
 */
class ChannelController extends Controller
{
    /** Channel value => the noun used in validation and confirmation copy. */
    private const LABELS = [
        'whatsapp' => 'WhatsApp number',
        'instagram' => 'Instagram account',
        'messenger' => 'Facebook Page',
    ];

    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);
        $limits = PlanLimits::for($workspace);
        $plan = $limits->plan();

        // withCount keeps the "N conversations / N campaigns" on each card to one query.
        $accounts = ChannelAccount::query()
            ->withCount(['conversations', 'campaigns'])
            ->orderBy('channel')
            ->orderBy('id')
            ->get();

        $used = $workspace->channelAccounts()
            ->selectRaw('channel, count(*) as aggregate')
            ->groupBy('channel')
            ->pluck('aggregate', 'channel');

        return Inertia::render('Channels', [
            'accounts' => $accounts->map(fn (ChannelAccount $account) => $this->payload($account))->all(),
            // NOTE: not "channels" — HandleInertiaRequests already shares a "channels"
            // prop for the header badges, and page props would override it.
            'channelOptions' => collect(ChannelType::cases())
                ->map(fn (ChannelType $channel) => [
                    'value' => $channel->value,
                    'label' => self::LABELS[$channel->value] ?? ucfirst($channel->value),
                    'used' => (int) ($used[$channel->value] ?? 0),
                    // Only Instagram is capped by the plan today (see PlanLimits::canConnect).
                    'max' => $channel === ChannelType::Instagram ? $plan?->max_instagram_accounts : null,
                    'canConnect' => $limits->canConnect($channel),
                ])
                ->values()
                ->all(),
            'webhook' => [
                'callbackUrl' => route('webhooks.meta.receive'),
                // Platform-wide secret: only surfaced to owners today (see maySeePlatformSecrets).
                'verifyToken' => $this->maySeePlatformSecrets($request) ? config('omniflow.meta.verify_token') : null,
                'graphVersion' => config('omniflow.meta.graph_version'),
                'appSecretConfigured' => (bool) config('omniflow.meta.app_secret'),
            ],
            // WhatsApp Embedded Signup: shown only when the Meta app is approved for
            // it, otherwise the manual form below is the only way to connect.
            'oauth' => app(MetaOAuth::class)->summary(
                (string) $workspace->slug,
                $this->maySeePlatformSecrets($request),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);

        $this->normaliseInput($request);

        $data = $request->validate([
            'channel' => ['required', Rule::in(array_keys(self::LABELS))],
            'name' => ['required', 'string', 'max:120'],
            // (channel, external_id) is globally unique: an asset can belong to one workspace.
            'external_id' => [
                'required', 'string', 'max:190',
                Rule::unique('channel_accounts', 'external_id')->where('channel', $request->input('channel')),
            ],
            'handle' => ['nullable', 'string', 'max:190'],
            'business_account_id' => ['nullable', 'string', 'max:190'],
            'access_token' => ['nullable', 'string', 'max:2000'],
            'token_expires_at' => ['nullable', 'date'],
        ], [
            'external_id.unique' => 'That account id is already connected — each Meta asset can belong to one workspace.',
        ]);

        $channel = ChannelType::from($data['channel']);

        if (! PlanLimits::for($workspace)->canConnect($channel)) {
            throw ValidationException::withMessages([
                'channel' => 'Your plan has reached its '.self::LABELS[$channel->value].' limit. Upgrade to connect more.',
            ]);
        }

        ChannelAccount::create([
            'channel' => $data['channel'],
            'name' => $data['name'],
            'external_id' => $data['external_id'],
            'handle' => $data['handle'] ?? null,
            'business_account_id' => $data['business_account_id'] ?? null,
            'access_token' => $data['access_token'] ?? null,
            'token_expires_at' => $data['token_expires_at'] ?? null,
            'status' => 'connected',
        ]);

        return back()->with('success', $data['name'].' connected.');
    }

    public function update(Request $request, string $workspace, ChannelAccount $channelAccount): RedirectResponse
    {
        $this->normaliseInput($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:190'],
            'business_account_id' => ['sometimes', 'nullable', 'string', 'max:190'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'token_expires_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['connected', 'disconnected'])],
        ]);

        if (array_key_exists('name', $data)) {
            $channelAccount->name = $data['name'];
        }

        if (array_key_exists('handle', $data)) {
            $channelAccount->handle = $data['handle'];
        }

        if (array_key_exists('business_account_id', $data)) {
            $channelAccount->business_account_id = $data['business_account_id'];
        }

        if (array_key_exists('token_expires_at', $data)) {
            $channelAccount->token_expires_at = $data['token_expires_at'];
        }

        // Only replace the token when a new one was actually pasted, so the
        // "reconnect" form can be submitted without re-entering it.
        if (! empty($data['access_token'])) {
            $channelAccount->access_token = $data['access_token'];
            $channelAccount->status = 'connected'; // a fresh token clears an earlier "reconnect" flag
        }

        if (array_key_exists('status', $data)) {
            $channelAccount->status = $data['status'];
        }

        $dirty = $channelAccount->isDirty();
        $channelAccount->save();

        return back()->with('success', $dirty ? $channelAccount->name.' updated.' : 'No changes to save.');
    }

    /**
     * "Test connection": read the asset back from Meta with the stored token.
     * A failure flags the account (outbound is broken) but never disconnects it,
     * because inbound webhooks do not need the token at all.
     */
    public function verify(Request $request, string $workspace, ChannelAccount $channelAccount): RedirectResponse
    {
        // Self-heal tokens that were stored before the input was normalised (or by
        // another path): a stray newline makes Meta answer "cannot parse access token".
        $token = ChannelAccount::normaliseToken($channelAccount->access_token);
        $externalId = ChannelAccount::normaliseId($channelAccount->external_id);

        if (blank($token)) {
            return back()->with('error', 'Add an access token before testing this connection.');
        }

        if ($token !== $channelAccount->access_token || $externalId !== $channelAccount->external_id) {
            $channelAccount->access_token = $token;
            $channelAccount->external_id = $externalId ?: $channelAccount->external_id;
            $channelAccount->save();
        }

        $host = rtrim((string) config("omniflow.meta.hosts.{$channelAccount->channel}", 'https://graph.facebook.com'), '/');
        $url = $host.'/'.config('omniflow.meta.graph_version').'/'.$channelAccount->external_id;

        $fields = match ($channelAccount->channel) {
            'whatsapp' => 'display_phone_number,verified_name,quality_rating',
            'instagram' => 'username,name',
            default => 'name',
        };

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->get($url, ['fields' => $fields]);
        } catch (ConnectionException $e) {
            return back()->with('error', 'Could not reach Meta: '.$e->getMessage());
        }

        if (! $response->successful()) {
            // A dead token only breaks sending, so flag it — unless the tenant has
            // deliberately disconnected the account, which we must not undo.
            $channelAccount->update([
                'status' => $channelAccount->status === 'disconnected' ? 'disconnected' : 'error',
            ]);

            $message = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());

            // By far the most common cause is a hand-copied token carrying whitespace,
            // quotes or a "Bearer " prefix, which we now strip on save — so the fix is
            // to re-paste it rather than to debug permissions.
            if (str_contains($message, 'Cannot parse') || str_contains($message, 'Invalid OAuth access token')) {
                return back()->with('error', 'Meta could not parse the access token. Re-open Edit and paste it again as a single line with no spaces, quotes or "Bearer " prefix — a WhatsApp/System User token starts with "EAA".');
            }

            return back()->with('error', 'Meta rejected these credentials: '.$message);
        }

        $channelAccount->update([
            // Clearing an earlier "error" flag, but never reconnecting a disabled account.
            'status' => $channelAccount->status === 'disconnected' ? 'disconnected' : 'connected',
            'meta' => array_merge($channelAccount->meta ?? [], [
                'profile' => $response->json(),
                'verified_at' => now()->toIso8601String(),
            ]),
        ]);

        return back()->with('success', $channelAccount->name.' is reachable.');
    }

    public function destroy(Request $request, string $workspace, ChannelAccount $channelAccount): RedirectResponse
    {
        $name = $channelAccount->name;

        // Conversations and contact identities CASCADE on delete, so removing an
        // account that has been used would erase history. Refuse and point at
        // disconnect (which keeps the data and stops ingestion) instead.
        if ($channelAccount->conversations()->exists()) {
            return back()->with('error', $name.' has conversation history — disconnect it instead of deleting.');
        }

        $channelAccount->delete();

        return back()->with('success', $name.' removed.');
    }

    /** @return array<string, mixed> */
    private function payload(ChannelAccount $account): array
    {
        $profile = $account->meta['profile'] ?? [];

        return [
            'id' => $account->id,
            'channel' => $account->channel,
            'name' => $account->name,
            'handle' => $account->handle,
            'externalId' => $account->external_id,
            'businessAccountId' => $account->business_account_id,
            'status' => $account->status,
            // A hand-edited or truncated encrypted value must not take down the whole
            // settings page; surface it as "no token" so the tenant can paste a new one.
            'hasToken' => rescue(fn () => filled($account->access_token), false),
            'tokenExpiresAt' => $account->token_expires_at?->toIso8601String(),
            'tokenExpired' => $account->token_expires_at?->isPast() ?? false,
            'lastWebhookAt' => $account->last_webhook_at?->toIso8601String(),
            'createdAt' => $account->created_at?->toIso8601String(),
            'conversations' => (int) $account->conversations_count,
            'campaigns' => (int) $account->campaigns_count,
            'qualityRating' => $profile['quality_rating'] ?? null,
        ];
    }

    /**
     * Meta's dashboard is the only source for these values and they are copied by
     * hand, so clean them up BEFORE validation — otherwise a pasted " PN1" would
     * slip past the unique rule and then collide with an existing "PN1" on insert.
     */
    private function normaliseInput(Request $request): void
    {
        $request->merge([
            'external_id' => ChannelAccount::normaliseId($request->input('external_id')),
            'business_account_id' => ChannelAccount::normaliseId($request->input('business_account_id')),
            'access_token' => ChannelAccount::normaliseToken($request->input('access_token')),
            'handle' => is_string($handle = $request->input('handle')) ? trim($handle) : $request->input('handle'),
        ]);
    }

    /**
     * The webhook verify token is shared by every tenant, so it is a platform
     * secret. Today the workspace owner doubles as the Meta app operator and
     * needs it to configure the app; move this behind a real platform-admin
     * guard once the app serves paying tenants.
     */
    private function maySeePlatformSecrets(Request $request): bool
    {
        return $request->attributes->get('workspace_role') === 'owner'
            || (bool) $request->user()?->is_super_admin;
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }
}
