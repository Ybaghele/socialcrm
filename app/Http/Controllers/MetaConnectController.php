<?php

namespace App\Http\Controllers;

use App\Models\ChannelAccount;
use App\Models\Workspace;
use App\Services\Meta\MetaOAuth;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WhatsApp Embedded Signup.
 *
 *   GET /w/{workspace}/channels/meta           send the owner to Meta's dialog
 *   GET /channels/meta/callback                bring them back with a business token
 *
 * The callback is deliberately NOT under /w/{workspace}: Meta only knows the one
 * redirect URI we registered. The workspace comes back in the signed `state`
 * parameter and is re-authorised there, so a pasted link cannot touch a tenant
 * the signed-in user does not own.
 */
class MetaConnectController extends Controller
{
    public function __construct(private readonly MetaOAuth $oauth) {}

    /** Owner-only: only they may grant an asset to the workspace. */
    public function start(Request $request, string $workspace): RedirectResponse
    {
        abort_unless($request->attributes->get('workspace_role') === 'owner', 403);
        abort_if($this->oauth->isEnabled() === false, 404);

        $user = $request->user();

        return redirect()->away($this->oauth->dialogUrl(
            $this->redirectUri(),
            $this->oauth->state($workspace, $user->getKey()),
        ));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $this->oauth->parseState($request->query('state'));

        abort_unless($state !== null, 400, 'Invalid connect state.');
        abort_unless($this->oauth->isEnabled(), 404);

        $user = $request->user();
        abort_unless($user && $user->getKey() === $state['user_id'], 403);

        $workspace = Workspace::where('slug', $state['workspace'])->first();
        abort_unless($workspace, 404);

        $role = $workspace->users()->whereKey($user->getKey())->first()?->pivot?->role;
        abort_unless($role === 'owner', 403);

        $channels = route('channels.index', ['workspace' => $workspace->slug]);

        if ($error = $request->query('error')) {
            return redirect($channels)->with('error', 'Connect cancelled: '.(string) ($request->query('error_description') ?: $error));
        }

        $code = (string) $request->query('code');

        if ($code === '') {
            return redirect($channels)->with('error', 'Meta did not return a code.');
        }

        try {
            $token = $this->oauth->upgradeToken($this->oauth->exchangeCode($code, $this->redirectUri()));
            $accounts = $this->oauth->whatsappBusinessAccounts($token['access_token']);
        } catch (ConnectionException $e) {
            return redirect($channels)->with('error', 'Could not reach Meta: '.$e->getMessage());
        } catch (\Throwable $e) {
            return redirect($channels)->with('error', 'Meta rejected the connection: '.$this->graphMessage($e));
        }

        $saved = Tenancy::run($workspace, fn () => $this->save($token['access_token'], $accounts));

        if ($saved['saved'] === 0) {
            return redirect($channels)->with(
                'error',
                $saved['skipped'] > 0
                    ? 'Those numbers are already connected to another workspace. Ask the owner to remove them there first.'
                    : 'Meta returned no phone numbers. Finish the signup in Meta Business Manager and try again.',
            );
        }

        $expires = $token['expires_in'] === null ? '' : ', valid ~'.(int) ceil($token['expires_in'] / 86400).' days';

        return redirect($channels)->with(
            'success',
            "{$saved['saved']} WhatsApp number(s) connected{$expires}."
            .($saved['subscribed'] ? '' : ' Warning: Meta would not subscribe the webhook, so messages may not arrive.'),
        );
    }

    /** Where Meta sends the customer back. Must be registered in the Meta app. */
    private function redirectUri(): string
    {
        return (string) (config('omniflow.meta.redirect_uri') ?: route('channels.meta.callback'));
    }

    /**
     * Upsert what the customer granted us. Runs inside Tenancy::run() so the
     * workspace-scoped model writes into the right tenant.
     *
     * @param  array<int, array{id: string, name: string, phone_numbers: array<int, array{id: string, display_phone_number: ?string, verified_name: ?string}>}>  $accounts
     * @return array{saved: int, skipped: int, subscribed: int}
     */
    private function save(string $token, array $accounts): array
    {
        $expiresAt = now()->addDays(60);
        $result = ['saved' => 0, 'skipped' => 0, 'subscribed' => 0];
        $subscribed = [];

        foreach ($accounts as $waba) {
            foreach ($waba['phone_numbers'] as $phone) {
                // (channel, external_id) is globally unique: an asset belongs to one
                // workspace. Never silently move a number that another tenant owns.
                $taken = ChannelAccount::withoutGlobalScope('workspace')
                    ->where('channel', 'whatsapp')
                    ->where('external_id', $phone['id'])
                    ->first();

                if ($taken && (int) $taken->workspace_id !== (int) Tenancy::id()) {
                    $result['skipped']++;

                    continue;
                }

                ChannelAccount::updateOrCreate(
                    ['channel' => 'whatsapp', 'external_id' => $phone['id']],
                    [
                        'name' => Str::limit((string) ($phone['verified_name'] ?: $waba['name'] ?: 'WhatsApp number'), 120, ''),
                        'handle' => $phone['display_phone_number'] ?? null,
                        'business_account_id' => $waba['id'],
                        'access_token' => $token,
                        'token_expires_at' => $expiresAt,
                        'status' => 'connected',
                    ],
                );

                $result['saved']++;
                $subscribed[$waba['id']] = true;
            }
        }

        foreach (array_keys($subscribed) as $wabaId) {
            $this->oauth->subscribeApp($token, (string) $wabaId) && $result['subscribed']++;
        }

        return $result;
    }

    private function graphMessage(\Throwable $e): string
    {
        $body = method_exists($e, 'response') ? $e->response() : null;

        return $body?->json('error.message') ?? Str::limit($e->getMessage(), 200);
    }
}
