<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMetaConnectionRequest;
use App\Http\Requests\UpdateMetaConnectionRequest;
use App\Models\MetaConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MetaConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $connection = MetaConnection::query()->first();

        return Inertia::render('Ads/MetaConnect', [
            'connection' => $connection ? [
                'ad_account_id' => $connection->ad_account_id,
                'status' => $connection->status,
                'connected' => true,
            ] : ['connected' => false],
            'authUrl' => $this->authUrl($request),
        ]);
    }

    public function authUrl(Request $request): string
    {
        $redirectUri = route('ads.meta.callback', ['workspace' => $request->attributes->get('workspace')?->slug ?? $request->route('workspace')]);
        $state = $this->state($request);

        $query = http_build_query([
            'client_id' => config('omniflow.meta.app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', ['ads_management', 'ads_read', 'business_management']),
            'response_type' => 'code',
        ]);

        return 'https://www.facebook.com/v22.0/dialog/oauth?'.$query;
    }

    public function redirect(Request $request): RedirectResponse
    {
        return redirect()->away($this->authUrl($request));
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        abort_if(! $code, 400, 'Missing authorization code');

        $redirectUri = route('ads.meta.callback', ['workspace' => $request->attributes->get('workspace')?->slug ?? $request->route('workspace')]);

        $response = Http::asForm()->post('https://graph.facebook.com/v22.0/oauth/access_token', [
            'client_id' => config('omniflow.meta.app_id'),
            'client_secret' => config('omniflow.meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ])->throw();

        $shortLived = $response->json('access_token');

        $longLived = Http::asForm()->get('https://graph.facebook.com/v22.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('omniflow.meta.app_id'),
            'client_secret' => config('omniflow.meta.app_secret'),
            'fb_exchange_token' => $shortLived,
        ])->throw()->json('access_token');

        MetaConnection::updateOrCreate(
            ['workspace_id' => $request->attributes->get('workspace')->id],
            [
                'access_token' => $longLived,
                'token_expires_at' => now()->addDays(60),
                'status' => 'connected',
                'meta' => ['scopes' => ['ads_management', 'ads_read', 'business_management']],
            ],
        );

        return redirect()->route('ads.campaigns.create', ['workspace' => $request->attributes->get('workspace')?->slug ?? $request->route('workspace')])
            ->with('success', 'Meta account connected successfully.');
    }

    private function state(Request $request): string
    {
        $payload = ($request->attributes->get('workspace')?->slug ?? $request->route('workspace')).'.'.$request->user()->id;

        return $payload.'.'.Str::substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 16);
    }
}
