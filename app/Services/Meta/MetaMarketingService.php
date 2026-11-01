<?php

namespace App\Services\Meta;

use App\Exceptions\MetaPublishException;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdSet;
use App\Models\ChannelAccount;
use App\Models\MetaConnection;
use App\Models\PublishLog;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publishes a wizard-built campaign to the Meta Marketing Graph API.
 *
 * The three Graph edges are called in order — campaign, ad set, then creative
 * and ad — inside one database transaction. Meta has no rollback, so anything
 * created before a later step fails is deleted again on a best-effort basis.
 *
 * Every object is created PAUSED on purpose: an unverified Meta app (or a
 * campaign built against a sandbox ad account) must never spend money just
 * because someone clicked Publish.
 */
class MetaMarketingService
{
    private const STATUS_PAUSED = 'PAUSED';

    public function publish(AdCampaign $campaign): AdCampaign
    {
        $campaign->loadMissing(['adSets.creatives']);

        /** @var array<string, mixed> $state */
        $state = [
            'request' => [],
            'response' => [],
            'platform_campaign_id' => null,
            'platform_ad_set_ids' => [],
            'platform_creative_ids' => [],
            'platform_ad_ids' => [],
            'created_campaign' => null,
        ];

        try {
            $connection = $this->connection();
            $adAccountId = $this->adAccountId($campaign, $connection);
            $token = $this->token($connection);
            $pageId = $this->pageId($connection);

            if ($campaign->adSets->isEmpty()) {
                throw new MetaPublishException(
                    step: 'ad_set',
                    userTitle: 'Campaign is missing an ad set',
                    userMessage: 'Add an ad set before publishing this campaign to Meta.',
                    rawMessage: 'No ad sets found for campaign '.$campaign->id.'.',
                );
            }
        } catch (MetaPublishException $e) {
            $this->writeLog($campaign, PublishLog::STATUS_FAILED, $e, $state);

            throw $e;
        }

        try {
            $campaign = DB::transaction(function () use ($campaign, $adAccountId, $token, $pageId, &$state): AdCampaign {
                $campaign->forceFill(['status' => 'processing'])->save();

                $platformCampaignId = $campaign->platform_campaign_id;

                if (! $platformCampaignId) {
                    $response = $this->post(
                        $adAccountId,
                        'campaigns',
                        $token,
                        $this->campaignPayload($campaign),
                        'campaign',
                        $state,
                    );

                    $platformCampaignId = $this->requireId($response, 'campaign', 'campaign');
                    $state['created_campaign'] = $platformCampaignId;
                    $campaign->forceFill(['platform_campaign_id' => $platformCampaignId])->save();
                }

                $state['platform_campaign_id'] = $platformCampaignId;

                foreach ($campaign->adSets as $adSet) {
                    $platformAdSetId = $adSet->platform_adset_id;

                    if (! $platformAdSetId) {
                        $response = $this->post(
                            $adAccountId,
                            'adsets',
                            $token,
                            $this->adSetPayload($campaign, $adSet, $platformCampaignId),
                            'ad_set',
                            $state,
                        );

                        $platformAdSetId = $this->requireId($response, 'ad_set', 'ad set');
                        $state['platform_ad_set_ids'][] = $platformAdSetId;
                        $adSet->forceFill(['platform_adset_id' => $platformAdSetId])->save();
                    }

                    foreach ($adSet->creatives as $creative) {
                        $platformCreativeId = $creative->platform_creative_id;

                        if (! $platformCreativeId) {
                            $response = $this->post(
                                $adAccountId,
                                'adcreatives',
                                $token,
                                $this->creativePayload($campaign, $creative, $pageId),
                                'ad_creative',
                                $state,
                            );

                            $platformCreativeId = $this->requireId($response, 'ad_creative', 'ad creative');
                            $state['platform_creative_ids'][] = $platformCreativeId;
                            $creative->forceFill(['platform_creative_id' => $platformCreativeId])->save();
                        }

                        if (! $creative->platform_ad_id) {
                            $response = $this->post(
                                $adAccountId,
                                'ads',
                                $token,
                                $this->adPayload($campaign, $creative, $platformAdSetId, $platformCreativeId),
                                'ad',
                                $state,
                            );

                            $platformAdId = $this->requireId($response, 'ad', 'ad');
                            $state['platform_ad_ids'][] = $platformAdId;
                            $creative->forceFill([
                                'platform_ad_id' => $platformAdId,
                                'status' => 'paused',
                            ])->save();
                        }
                    }

                    $adSet->forceFill(['status' => 'paused'])->save();
                }

                $campaign->forceFill(['status' => 'paused'])->save();

                return $campaign->fresh(['adSets.creatives']);
            });
        } catch (MetaPublishException $e) {
            $this->cleanup($adAccountId, $token, $state);
            $this->writeLog($campaign, PublishLog::STATUS_FAILED, $e, $state);

            throw $e;
        } catch (Throwable $e) {
            $wrapped = new MetaPublishException(
                step: 'publish',
                userTitle: 'Could not publish the campaign',
                userMessage: 'An unexpected error stopped the Meta publish. Please try again.',
                rawMessage: $e->getMessage(),
                previous: $e,
            );

            $this->cleanup($adAccountId, $token, $state);
            $this->writeLog($campaign, PublishLog::STATUS_FAILED, $wrapped, $state);

            throw $wrapped;
        }

        $this->writeLog($campaign, PublishLog::STATUS_SUCCESS, null, $state);

        return $campaign;
    }

    private function connection(): MetaConnection
    {
        $connection = MetaConnection::query()->orderByDesc('id')->first();

        if (! $connection) {
            throw new MetaPublishException(
                step: 'connection',
                userTitle: 'Connect a Meta account first',
                userMessage: 'No Meta connection is saved for this workspace. Connect Meta from the Ads → Meta screen, then publish again.',
                rawMessage: 'No MetaConnection row exists for the current workspace.',
            );
        }

        return $connection;
    }

    private function adAccountId(AdCampaign $campaign, MetaConnection $connection): string
    {
        $raw = $campaign->ad_account_id ?: $connection->ad_account_id;
        $id = trim((string) preg_replace('/^act_/i', '', trim((string) $raw)));

        if ($id === '') {
            throw new MetaPublishException(
                step: 'connection',
                userTitle: 'Add a Meta ad account ID',
                userMessage: 'Save the ad account ID (for example act_1234567890) in the campaign or on the Meta connection, then publish again.',
                rawMessage: 'No ad_account_id on the campaign or Meta connection.',
            );
        }

        return $id;
    }

    private function token(MetaConnection $connection): string
    {
        // The `encrypted` cast on MetaConnection decrypts this through Laravel's
        // Crypt system, so the plaintext token never sits in the database.
        $token = trim((string) $connection->access_token);

        if ($token === '') {
            throw new MetaPublishException(
                step: 'connection',
                userTitle: 'Reconnect your Meta account',
                userMessage: 'The saved Meta access token is missing. Reconnect Meta and publish again.',
                rawMessage: 'MetaConnection access_token is empty.',
            );
        }

        return $token;
    }

    private function pageId(MetaConnection $connection): string
    {
        $pageId = trim((string) config('omniflow.meta.ads.page_id', ''));

        if ($pageId === '') {
            $pageId = trim((string) (data_get($connection->meta, 'page_id') ?? ''));
        }

        if ($pageId === '') {
            $account = ChannelAccount::query()
                ->where('channel', 'messenger')
                ->orderBy('id')
                ->first();

            if (! $account) {
                $account = ChannelAccount::query()
                    ->where('channel', 'instagram')
                    ->orderBy('id')
                    ->first();
            }

            if ($account) {
                $pageId = trim((string) (data_get($account->meta, 'page_id') ?? ''));

                // A Messenger channel's external id is the Facebook Page id.
                if ($pageId === '' && $account->channel === 'messenger') {
                    $pageId = trim((string) $account->external_id);
                }
            }
        }

        if ($pageId === '') {
            throw new MetaPublishException(
                step: 'ad_creative',
                userTitle: 'Connect a Facebook Page first',
                userMessage: 'Meta needs a Facebook Page to own the ad creative. Connect a Page from Channels, or set META_ADS_PAGE_ID, then publish again.',
                rawMessage: 'No page_id could be resolved from the Meta connection or channel accounts.',
            );
        }

        return $pageId;
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignPayload(AdCampaign $campaign): array
    {
        $objective = strtoupper(trim((string) $campaign->objective));
        $specialCategory = strtoupper(trim((string) $campaign->special_category));

        return [
            'name' => $campaign->name,
            'objective' => $objective !== '' ? $objective : 'OUTCOME_TRAFFIC',
            'status' => self::STATUS_PAUSED,
            'buying_type' => 'AUCTION',
            'special_ad_categories' => json_encode($specialCategory !== '' ? [$specialCategory] : []),
            // Meta requires this flag when the budget lives on the ad set.
            'is_adset_budget_sharing_enabled' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adSetPayload(AdCampaign $campaign, AdSet $adSet, string $platformCampaignId): array
    {
        $budgetMinor = $this->budgetMinor($adSet->budget_amount ?? $campaign->budget_amount);
        $isLifetime = ($campaign->budget_type ?? 'daily') === 'lifetime';

        $payload = [
            'campaign_id' => $platformCampaignId,
            'name' => $adSet->name ?: $campaign->name,
            'status' => self::STATUS_PAUSED,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $this->optimizationGoal($adSet->optimization_goal),
            'bid_amount' => (int) config('omniflow.meta.ads.default_bid_amount', 100),
            'bid_strategy' => 'LOWEST_COST_WITH_BID_CAP',
            'targeting' => json_encode($this->targeting($adSet)),
        ];

        if ($budgetMinor > 0) {
            $payload[$isLifetime ? 'lifetime_budget' : 'daily_budget'] = $budgetMinor;
        }

        $audience = is_array($adSet->audience_json) ? $adSet->audience_json : [];

        if (! empty($audience['promoted_object']) && is_array($audience['promoted_object'])) {
            $payload['promoted_object'] = json_encode($audience['promoted_object']);
        } elseif (! empty($audience['pixel_id'])) {
            $payload['promoted_object'] = json_encode([
                'pixel_id' => (string) $audience['pixel_id'],
                'custom_event_type' => (string) ($audience['custom_event_type'] ?? 'PURCHASE'),
            ]);
        }

        if ($adSet->start_at) {
            $payload['start_time'] = $adSet->start_at->toIso8601String();
        }

        if ($adSet->end_at) {
            $payload['end_time'] = $adSet->end_at->toIso8601String();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function targeting(AdSet $adSet): array
    {
        $audience = is_array($adSet->audience_json) ? $adSet->audience_json : [];
        $placements = is_array($adSet->placements_json) ? $adSet->placements_json : [];

        $ageMin = max(13, (int) ($audience['age_min'] ?? 18));
        $ageMax = min(65, max($ageMin, (int) ($audience['age_max'] ?? 65)));

        $targeting = [
            'age_min' => $ageMin,
            'age_max' => $ageMax,
        ];

        $genders = array_values(array_filter(
            array_map('intval', (array) ($audience['genders'] ?? [])),
            fn (int $gender): bool => in_array($gender, [1, 2], true),
        ));

        if ($genders !== []) {
            $targeting['genders'] = $genders;
        }

        if (! empty($audience['geo_locations']) && is_array($audience['geo_locations'])) {
            $targeting['geo_locations'] = $audience['geo_locations'];
        } else {
            $locations = array_values(array_filter(
                array_map('strval', (array) ($audience['locations'] ?? [])),
                fn (string $location): bool => trim($location) !== '',
            ));

            $targeting['geo_locations'] = [
                'countries' => $locations !== []
                    ? $locations
                    : [(string) config('omniflow.meta.ads.default_country', 'IN')],
            ];
        }

        foreach (['interests', 'behaviors', 'custom_audiences', 'excluded_custom_audiences'] as $key) {
            if (! empty($audience[$key]) && is_array($audience[$key])) {
                $targeting[$key] = $audience[$key];
            }
        }

        $platforms = [];
        $positions = [];

        $placementMap = [
            'facebook' => ['facebook', 'facebook_positions', ['feed']],
            'instagram' => ['instagram', 'instagram_positions', ['stream']],
            'messenger' => ['messenger', 'messenger_positions', ['messenger_home']],
            'audience_network' => ['audience_network', 'audience_network_positions', ['classic']],
        ];

        foreach ($placementMap as $key => [$platform, $positionKey, $defaults]) {
            if (! empty($placements[$key])) {
                $platforms[] = $platform;
                $positions[$positionKey] = $defaults;
            }
        }

        if ($platforms !== []) {
            $targeting['publisher_platforms'] = $platforms;
            $targeting = array_merge($targeting, $positions);
        }

        return $targeting;
    }

    /**
     * @return array<string, mixed>
     */
    private function creativePayload(AdCampaign $campaign, AdCreative $creative, string $pageId): array
    {
        $link = $creative->destination_url ?: (string) config('app.url');

        $linkData = [
            'link' => $link,
            'message' => $creative->primary_text ?: $campaign->name,
            'name' => $creative->headline ?: $campaign->name,
            'call_to_action' => [
                'type' => $creative->cta ?: 'LEARN_MORE',
                'value' => ['link' => $link],
            ],
        ];

        if ($creative->description) {
            $linkData['description'] = $creative->description;
        }

        if ($creative->media_url) {
            $linkData['picture'] = $creative->media_url;
        }

        return [
            'name' => $creative->name ?: $campaign->name,
            'object_story_spec' => json_encode([
                'page_id' => $pageId,
                'link_data' => $linkData,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adPayload(AdCampaign $campaign, AdCreative $creative, string $platformAdSetId, string $platformCreativeId): array
    {
        return [
            'name' => $creative->headline ?: $campaign->name,
            'adset_id' => $platformAdSetId,
            'creative' => json_encode(['creative_id' => $platformCreativeId]),
            'status' => self::STATUS_PAUSED,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function post(string $adAccountId, string $edge, string $token, array $payload, string $step, array &$state): array
    {
        $url = $this->baseUrl().'/act_'.$adAccountId.'/'.$edge;

        $state['request'] = [
            'step' => $step,
            'url' => $url,
            'payload' => $payload,
        ];
        $state['response'] = [];

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('omniflow.meta.ads.timeout', 30))
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new MetaPublishException(
                step: $step,
                userTitle: 'Could not reach Meta',
                userMessage: 'The server could not reach the Meta Graph API. Check the outbound connection and try again.',
                rawMessage: $e->getMessage(),
                previous: $e,
            );
        }

        $body = $response->json();
        $state['response'] = is_array($body) ? $body : ['body' => $response->body()];

        if ($response->failed()) {
            throw $this->graphException($step, $response, $body);
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function requireId(array $response, string $step, string $label): string
    {
        $id = trim((string) ($response['id'] ?? ''));

        if ($id === '') {
            throw new MetaPublishException(
                step: $step,
                userTitle: 'Meta did not return an object id',
                userMessage: "Meta accepted the request but did not return an id for the {$label}. Try publishing again.",
                rawMessage: 'Missing id in Meta response: '.json_encode($response),
                response: $response,
            );
        }

        return $id;
    }

    private function graphException(string $step, Response $response, mixed $body): MetaPublishException
    {
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        $message = trim((string) ($error['error_user_msg'] ?? $error['message'] ?? $response->body()));
        $errorTitle = trim((string) ($error['error_user_title'] ?? ''));
        $code = isset($error['code']) ? (string) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (string) $error['error_subcode'] : null;
        $type = isset($error['type']) ? (string) $error['type'] : null;
        $traceId = isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : '';

        [$userTitle, $userMessage] = $this->friendlyError($code, $type, $message, $response->status(), $errorTitle);

        return new MetaPublishException(
            step: $step,
            userTitle: $userTitle,
            userMessage: $userMessage,
            rawMessage: $traceId !== '' ? "{$message} (fbtrace_id: {$traceId})" : $message,
            errorCode: $code,
            errorSubcode: $subcode,
            errorType: $type,
            response: is_array($body) ? $body : null,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function friendlyError(?string $code, ?string $type, string $message, int $httpStatus, string $metaTitle = ''): array
    {
        $lower = Str::lower($message);

        if ($httpStatus === 429 || in_array($code, ['4', '17', '32', '613', '80000', '80001', '80002', '80003', '80004', '80005', '80006'], true)) {
            return [
                'Meta is rate-limiting publishing',
                'Meta is receiving too many requests right now. Wait a few minutes, then click Publish again.',
            ];
        }

        if (in_array($code, ['190', '102'], true) || Str::contains($lower, ['access token', 'session has expired', 'token expired', 'invalid oauth'])) {
            return [
                'Your Meta connection expired',
                'Meta rejected the saved access token. Reconnect your Meta account from the Ads → Meta screen, then publish again.',
            ];
        }

        if (Str::contains($lower, ['payment', 'billing', 'credit card', 'funding', 'insufficient funds', 'no payment method'])) {
            return [
                'The ad account has no valid payment method',
                'Meta will not publish ads until a card or funding source is attached. Add one in Meta Ads Manager → Billing, then try again.',
            ];
        }

        if (in_array($code, ['200', '10', '2635'], true) || Str::contains($lower, ['permission', 'not authorized', 'ads_management'])) {
            return [
                'The Meta app is missing ad permissions',
                'Grant ads_management and ads_read to the app, and make sure the ad account is shared with it, then publish again.',
            ];
        }

        if ($code === '270' || Str::contains($lower, ['developer', 'development mode', 'not available'])) {
            return [
                'This Meta app is still in development mode',
                'Add the publishing user as a tester or developer on the Meta app, or switch the app to Live once Business Verification is complete.',
            ];
        }

        if (Str::contains($lower, ['special ad category', 'special_ad_categories'])) {
            return [
                'Meta needs a special ad category',
                'Choose the correct special ad category (credit, employment, housing or social issues) and publish again.',
            ];
        }

        if (Str::contains($lower, ['budget', 'bid_amount', 'daily_budget', 'lifetime_budget', 'bid amount'])) {
            return [
                'Meta rejected the budget',
                'Check the budget amount, currency and bid settings, then publish again.',
            ];
        }

        if ($httpStatus >= 500 || in_array($code, ['1', '2'], true)) {
            return [
                'Meta had a temporary problem',
                'The Graph API returned a server error. Wait a moment and try again.',
            ];
        }

        if ($metaTitle !== '') {
            return ['Meta rejected the campaign', $metaTitle];
        }

        return [
            'Meta rejected the campaign',
            'Meta returned an error while creating this campaign. Open the raw response below for the exact reason.',
        ];
    }

    /**
     * Best-effort cleanup of Meta objects created before a later step failed.
     *
     * @param  array<string, mixed>  $state
     */
    private function cleanup(string $adAccountId, string $token, array $state): void
    {
        $campaignId = $state['created_campaign'] ?? null;

        if (! $campaignId) {
            return;
        }

        try {
            Http::withToken($token)
                ->timeout(10)
                ->delete($this->baseUrl().'/'.$campaignId);
        } catch (Throwable $e) {
            Log::warning('Meta publish cleanup failed', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeLog(AdCampaign $campaign, string $status, ?MetaPublishException $exception, array $state): void
    {
        try {
            $workspace = Workspace::query()->find($campaign->workspace_id);

            if (! $workspace) {
                return;
            }

            $log = Tenancy::run($workspace, fn (): PublishLog => PublishLog::create([
                'workspace_id' => $campaign->workspace_id,
                'ad_campaign_id' => $campaign->id,
                'platform' => 'meta',
                'status' => $status,
                'step' => $exception?->step ?? 'complete',
                'error_code' => $exception?->errorCode,
                'error_subcode' => $exception?->errorSubcode,
                'error_type' => $exception?->errorType,
                'error_message' => $exception?->rawMessage,
                'error_user_title' => $exception?->userTitle,
                'error_user_message' => $exception?->userMessage,
                'request_payload' => $state['request'] ?: null,
                'response_payload' => $state['response'] ?: null,
                'trace' => $exception?->traceSummary() ?? $this->successTrace($state),
            ]));

            if ($exception) {
                $exception->publishLogId = $log->id;
            }
        } catch (Throwable $e) {
            Log::error('Could not persist Meta publish log', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function successTrace(array $state): string
    {
        return implode(' | ', [
            'step=complete',
            'campaign_id='.($state['platform_campaign_id'] ?? 'existing'),
            'ad_set_ids='.implode(',', $state['platform_ad_set_ids']),
            'creative_ids='.implode(',', $state['platform_creative_ids']),
            'ad_ids='.implode(',', $state['platform_ad_ids']),
        ]);
    }

    private function budgetMinor(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round((float) $amount * 100);
    }

    private function optimizationGoal(?string $goal): string
    {
        $goal = strtoupper(trim((string) $goal));

        $allowed = [
            'NONE', 'APP_INSTALLS', 'BRAND_AWARENESS', 'CONVERSIONS', 'EVENT_RESPONSES',
            'IMPRESSIONS', 'LEAD_GENERATION', 'LINK_CLICKS', 'OFFSITE_CONVERSIONS',
            'PAGE_LIKES', 'POST_ENGAGEMENT', 'REACH', 'SOCIAL_IMPRESSIONS', 'VALUE',
            'LANDING_PAGE_VIEWS',
        ];

        return in_array($goal, $allowed, true) ? $goal : 'LINK_CLICKS';
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('omniflow.meta.hosts.marketing', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('omniflow.meta.graph_version', 'v22.0'), '/');

        return "{$base}/{$version}";
    }
}
