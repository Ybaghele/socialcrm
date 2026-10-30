<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdCampaignRequest;
use App\Http\Requests\UpdateAdCampaignRequest;
use App\Models\AdCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        $campaign = AdCampaign::create($this->campaignAttributes($validated));
        $this->syncAdSetAndCreative($campaign, $validated);

        $workspace = $request->attributes->get('workspace');
        $slug = $workspace?->slug ?? $request->route('workspace') ?? $request->route('slug');

        if ($slug) {
            return redirect()->route('ads.campaigns.edit', [
                'workspace' => $slug,
                'campaign' => $campaign->id,
            ])->with('success', 'Ad campaign draft saved.');
        }

        return redirect()->route('ads.campaigns.index')->with('success', 'Ad campaign draft saved.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $workspace, AdCampaign $campaign): Response
    {
        $campaign->load(['adSets.creatives']);

        return Inertia::render('Ads/ShowCampaign', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $workspace, AdCampaign $campaign): Response
    {
        $campaign->load(['adSets.creatives']);

        return Inertia::render('Ads/CreateCampaignWizard', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdCampaignRequest $request, string $workspace, AdCampaign $campaign): RedirectResponse
    {
        $validated = $request->validated();

        $campaign->update($this->campaignAttributes($validated));
        $this->syncAdSetAndCreative($campaign, $validated);

        return back()->with('success', 'Ad campaign draft updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $workspace, AdCampaign $campaign): RedirectResponse
    {
        $campaign->delete();

        return redirect()->route('ads.campaigns.index')->with('success', 'Ad campaign archived.');
    }

    /**
     * Campaign-level columns captured by the wizard.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function campaignAttributes(array $validated): array
    {
        return Arr::only($validated, [
            'platform',
            'ad_account_id',
            'name',
            'objective',
            'special_category',
            'budget_type',
            'budget_amount',
            'status',
        ]);
    }

    /**
     * Persist the single ad set and creative the wizard collects, so drafts
     * round-trip back into the edit form instead of being silently dropped.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncAdSetAndCreative(AdCampaign $campaign, array $validated): void
    {
        $adSetAttributes = Arr::only($validated, [
            'audience_json',
            'placements_json',
            'optimization_goal',
            'start_at',
            'end_at',
        ]);

        $adSet = $campaign->adSets()->orderBy('id')->first();

        if ($adSet === null) {
            $adSet = $campaign->adSets()->create([
                'name' => $campaign->name,
                'status' => 'draft',
                ...$adSetAttributes,
            ]);
        } elseif ($adSetAttributes !== []) {
            $adSet->update($adSetAttributes);
        }

        $creativeAttributes = Arr::only($validated, [
            'format',
            'media_url',
            'primary_text',
            'headline',
            'description',
            'cta',
            'destination_url',
        ]);

        $creative = $adSet->creatives()->orderBy('id')->first();

        if ($creative === null) {
            if ($this->hasCreativeContent($creativeAttributes)) {
                $adSet->creatives()->create([
                    'name' => $campaign->name,
                    'status' => 'draft',
                    ...$creativeAttributes,
                ]);
            }

            return;
        }

        $creative->update($creativeAttributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasCreativeContent(array $attributes): bool
    {
        foreach (['media_url', 'primary_text', 'headline', 'description', 'destination_url'] as $key) {
            if (filled($attributes[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
