<?php

namespace App\Http\Controllers;

use App\Models\MetaConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MetaAdsBillingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ad_account_id' => ['required', 'string'],
        ]);

        $connection = MetaConnection::query()->first();

        if (! $connection || ! $connection->access_token) {
            return response()->json([
                'ok' => false,
                'billing_ready' => false,
                'warning' => "Payment method is missing. Please add your client’s card",
                'cta' => 'https://www.facebook.com/ads/manager/account_settings/account_billing/',
            ]);
        }

        try {
            $response = Http::withToken($connection->access_token)
                ->get("https://graph.facebook.com/v22.0/act_{$data['ad_account_id']}", [
                    'fields' => 'id,name,account_status,currency,amount_spent,balance,funding_source_details',
                ])
                ->throw();

            $funding = $response->json('funding_source_details');
            $billingReady = ! empty($funding);

            if (! $billingReady) {
                return response()->json([
                    'ok' => true,
                    'billing_ready' => false,
                    'warning' => "Payment method is missing. Please add your client’s card",
                    'cta' => 'https://www.facebook.com/ads/manager/account_settings/account_billing/',
                    'account_status' => $response->json('account_status'),
                ]);
            }

            return response()->json([
                'ok' => true,
                'billing_ready' => true,
                'account' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'billing_ready' => false,
                'warning' => "Payment method is missing. Please add your client’s card",
                'cta' => 'https://www.facebook.com/ads/manager/account_settings/account_billing/',
            ], 200);
        }
    }
}
