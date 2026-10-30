<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdCampaignRequest;
use App\Http\Requests\UpdateAdCampaignRequest;
use App\Models\AdCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdCampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $campaigns = AdCampaign::query()
            ->withCount('adSets')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Ads/CampaignDashboard', [
            'campaigns' => $campaigns->map(fn (AdCampaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'platform' => $campaign->platform,
                'objective' => $campaign->objective,
                'budget_type' => $campaign->budget_type,
                'budget_amount' => $campaign->budget_amount,
                'status' => $campaign->status,
                'ad_sets_count' => $campaign->ad_sets_count,
                'updated_at' => $campaign->updated_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Ads/CreateCampaignWizard');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAdCampaignRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['status'] = $validated['status'] ?? 'draft';

        $campaign = AdCampaign::create($validated);

        $workspace = $request->attributes->get('workspace');
        $slug = $workspace?->slug ?? $request->route('workspace');

        if (! $slug && $workspace) {
            $slug = $workspace->slug;
        }

        return redirect()->route('ads.campaigns.edit', [
            'workspace' => $slug,
            'ad_campaign' => $campaign->id,
        ])->with('success', 'Ad campaign draft saved.');
    }

    /**
     * Display the specified resource.
     */
    public function show(AdCampaign $adCampaign): Response
    {
        $adCampaign->load(['adSets.creatives']);

        return Inertia::render('Ads/ShowCampaign', [
            'campaign' => $adCampaign,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AdCampaign $adCampaign): Response
    {
        $adCampaign->load(['adSets.creatives']);

        return Inertia::render('Ads/CreateCampaignWizard', [
            'campaign' => $adCampaign,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdCampaignRequest $request, AdCampaign $adCampaign): RedirectResponse
    {
        $validated = $request->validated();
        $adCampaign->update($validated);

        return back()->with('success', 'Ad campaign draft updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AdCampaign $adCampaign): RedirectResponse
    {
        $adCampaign->delete();

        return redirect()->route('ads.campaigns.index')->with('success', 'Ad campaign archived.');
    }
}
