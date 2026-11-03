<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Services\Ads\AdProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Provider-driven ad account billing check. The platform is optional so the
 * existing Meta-only wizard call keeps working, while the Google and LinkedIn
 * shells return their coming-soon payload.
 */
class AdBillingController extends Controller
{
    public function __invoke(Request $request, AdProviderFactory $providers): JsonResponse
    {
        $data = $request->validate([
            'ad_account_id' => ['required', 'string'],
            'platform' => ['nullable', Rule::in(['meta', 'google', 'linkedin'])],
        ]);

        $campaign = new AdCampaign([
            'platform' => $data['platform'] ?? 'meta',
            'ad_account_id' => $data['ad_account_id'],
        ]);

        return response()->json($providers->forCampaign($campaign)->checkBillingStatus($campaign));
    }
}
