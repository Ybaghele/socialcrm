<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Jobs\Campaigns\ProcessCampaignEnrollments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET    /w/{workspace}/campaigns           campaigns + broadcast stats
 * POST   /w/{workspace}/campaigns           create a funnel from the modal
 * PATCH  /w/{workspace}/campaigns/{campaign} pause / resume / complete
 *
 * Campaigns are tenant-scoped through the BelongsToWorkspace global scope, and
 * the counters rendered on each card (delivered / replied / won) are the
 * denormalised columns the queue jobs maintain — so this screen never has to
 * count enrollments to draw itself.
 */
class CampaignController extends Controller
{
    /**
     * The three funnels offered by the "Create New Campaign" modal, mapped onto
     * the (channel, type, trigger) columns the automation engine actually stores.
     */
    private const FUNNELS = [
        'instagram_comment' => [
            'channel' => 'instagram',
            'type' => 'comment_to_dm',
            'trigger' => 'comment_keyword',
            'target' => 'All Reels & Feed Posts',
        ],
        'whatsapp_broadcast' => [
            'channel' => 'whatsapp',
            'type' => 'broadcast',
            'trigger' => 'manual',
            'target' => 'All opted-in subscribers',
        ],
        'messenger_lead' => [
            'channel' => 'messenger',
            'type' => 'ad_click_welcome',
            'trigger' => 'ad_click',
            'target' => 'Ad click-to-Message',
        ],
    ];

    /** Cards sort by how alive the campaign is, then newest first. */
    private const STATUS_ORDER = ['active', 'scheduled', 'paused', 'draft', 'completed', 'failed'];

    public function index(Request $request): Response
    {
        $campaigns = Campaign::query()
            ->with([
                'triggers:id,campaign_id,type,keywords',
                'segment:id,name',
                'template:id,name',
            ])
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn (Campaign $campaign) => array_search($campaign->status, self::STATUS_ORDER, true))
            ->values();

        // Stats come straight off the denormalised counters.
        $delivered = (int) $campaigns->sum('delivered_count');
        $replied = (int) $campaigns->sum('replied_count');
        $won = (int) $campaigns->sum('won_count');
        $costPaise = (int) $campaigns->sum('cost_paise');

        return Inertia::render('Campaigns', [
            'campaigns' => $campaigns->map(fn (Campaign $campaign) => $this->payload($campaign))->all(),
            'stats' => [
                'broadcastsSent' => $delivered,
                'replyRate' => $delivered ? round($replied / $delivered * 100, 1) : 0.0,
                'capturedLeads' => $won,
                'costPerAcquisition' => $won ? round($costPaise / $won / 100, 2) : 0.0,
                'activeCount' => $campaigns->whereIn('status', ['active', 'scheduled'])->count(),
            ],
            'funnels' => collect(self::FUNNELS)
                ->map(fn (array $funnel, string $key) => [
                    'value' => $key,
                    'channel' => $funnel['channel'],
                    'target' => $funnel['target'],
                ])
                ->values(),
            // Everything the WhatsApp broadcast modal needs to render itself:
            // which approved templates exist, which number would send them, and
            // how many opted-in contacts the audience resolves to.
            'broadcast' => [
                'templates' => MessageTemplate::query()
                    ->where('status', 'approved')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (MessageTemplate $template) => [
                        'id' => $template->id,
                        'name' => $template->name,
                        'language' => $template->language,
                        'category' => $template->category,
                        'body' => $template->bodyText(),
                        'variables' => $template->placeholderCount(),
                        'channelAccountId' => $template->channel_account_id,
                    ])
                    ->all(),
                'accounts' => ChannelAccount::query()
                    ->where('channel', 'whatsapp')
                    ->where('status', 'connected')
                    ->get(['id', 'name'])
                    ->map(fn (ChannelAccount $account) => ['id' => $account->id, 'name' => $account->name])
                    ->all(),
                'optedInCount' => Contact::query()->where('is_opted_in', true)->count(),
                'pendingTemplates' => MessageTemplate::query()->whereIn('status', ['pending', 'in_review'])->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'funnel' => ['required', Rule::in(array_keys(self::FUNNELS))],
            'message' => ['nullable', 'string', 'max:2000'],
            'keywords' => ['nullable', 'string', 'max:300'],
            // WhatsApp broadcast extras.
            'template_id' => ['nullable', 'integer'],
            'template_variables' => ['nullable', 'array'],
            'template_variables.*' => ['nullable', 'string', 'max:500'],
            'channel_account_id' => ['nullable', 'integer'],
        ]);

        $funnel = self::FUNNELS[$data['funnel']];

        if ($funnel['type'] === 'broadcast') {
            return $this->storeBroadcast($request, $data, $funnel);
        }

        if (trim((string) ($data['message'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'message' => 'Write the message this funnel should send.',
            ]);
        }

        $campaign = Campaign::create([
            'name' => $data['name'],
            'description' => $this->describe($funnel['type']),
            'type' => $funnel['type'],
            'channel' => $funnel['channel'],
            'status' => 'active',
            'message_body' => $data['message'],
            'audience_description' => ['label' => $funnel['target']],
            'started_at' => now(),
            'created_by' => $request->user()->id,
            // Best-effort: attach a sending account for the channel if one exists.
            'channel_account_id' => ChannelAccount::query()
                ->where('channel', $funnel['channel'])
                ->value('id'),
        ]);

        $keywords = collect(explode(',', (string) $data['keywords']))
            ->map(fn (string $keyword) => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($funnel['trigger'] === 'comment_keyword' || $keywords !== []) {
            CampaignTrigger::create([
                'campaign_id' => $campaign->id,
                'type' => $funnel['trigger'],
                'match_type' => 'contains',
                'keywords' => $keywords ?: null,
                'scope' => $funnel['trigger'] === 'comment_keyword' ? ['all_posts' => true] : null,
            ]);
        }

        return back()->with('success', "Campaign “{$campaign->name}” is live.");
    }

    /**
     * A WhatsApp broadcast is a template send to the opted-in audience. It has to
     * carry an approved template (Meta rejects free-form bulk messages), so this
     * path validates the template, snapshots its variable values and lets the
     * scheduled `campaigns:process` command enroll + deliver.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $funnel
     */
    private function storeBroadcast(Request $request, array $data, array $funnel): RedirectResponse
    {
        $template = MessageTemplate::query()->find($data['template_id'] ?? 0);

        if (! $template || $template->status !== 'approved') {
            throw ValidationException::withMessages([
                'template_id' => 'Pick a template Meta has approved. Sync Templates if yours is missing.',
            ]);
        }

        $accountId = $data['channel_account_id'] ?? $template->channel_account_id;
        $account = ChannelAccount::query()
            ->whereKey($accountId)
            ->where('channel', 'whatsapp')
            ->where('status', 'connected')
            ->first();

        if (! $account || $account->id !== $template->channel_account_id) {
            throw ValidationException::withMessages([
                'channel_account_id' => 'That template belongs to a different WhatsApp number. Reconnect it on Channels.',
            ]);
        }

        // Pad/trim the operator's values so the count always matches the template.
        $expected = $template->placeholderCount();
        $variables = array_slice(array_pad(array_values($data['template_variables'] ?? []), $expected, ''), 0, $expected);

        $campaign = Campaign::create([
            'name' => $data['name'],
            'description' => $this->describe('broadcast'),
            'type' => 'broadcast',
            'channel' => 'whatsapp',
            'status' => 'active',
            'message_body' => $template->bodyText(),
            'audience_description' => ['label' => $funnel['target']],
            'started_at' => now(),
            'created_by' => $request->user()->id,
            'channel_account_id' => $account->id,
            'message_template_id' => $template->id,
            'template_variables' => $variables,
        ]);

        // Enroll + start delivering right away (and keep going on the schedule).
        ProcessCampaignEnrollments::dispatch()->afterCommit();

        $audience = Contact::query()->where('is_opted_in', true)->count();

        return back()->with(
            'success',
            "“{$campaign->name}” is live and enrolling {$audience} opted-in contact".($audience === 1 ? '' : 's').'.',
        );
    }

    public function update(Request $request, string $workspace, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'paused', 'completed'])],
        ]);

        $campaign->status = $data['status'];

        if ($data['status'] === 'active' && ! $campaign->started_at) {
            $campaign->started_at = now();
        }

        if ($data['status'] === 'completed') {
            $campaign->ended_at = now();
        }

        $campaign->save();

        return back()->with('success', "Campaign “{$campaign->name}” is now {$campaign->status}.");
    }

    /** @return array<string, mixed> */
    private function payload(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'type' => $campaign->type,
            'channel' => $campaign->channel,
            'status' => $campaign->status,
            'trigger' => $this->triggerLabel($campaign),
            'target' => $campaign->audience_description['label'] ?? $campaign->segment?->name ?? 'All contacts',
            'template' => $campaign->template?->name,
            'enrolled' => (int) $campaign->enrolled_count,
            'delivered' => (int) $campaign->delivered_count,
            'replied' => (int) $campaign->replied_count,
            'replyRate' => $campaign->reply_rate,
            'leadsWon' => (int) $campaign->won_count,
            'startedAt' => $campaign->started_at?->toIso8601String(),
        ];
    }

    private function triggerLabel(Campaign $campaign): string
    {
        if ($campaign->triggers->isEmpty()) {
            return 'Manual';
        }

        return $campaign->triggers
            ->map(function (CampaignTrigger $trigger) {
                $keywords = collect($trigger->keywords ?? [])->implode(', ');

                return match ($trigger->type) {
                    'comment_keyword' => $keywords !== '' ? "IG comment: {$keywords}" : 'IG comment',
                    'message_keyword' => $keywords !== '' ? "DM keyword: {$keywords}" : 'DM keyword',
                    'webhook' => 'Webhook',
                    'ad_click' => 'Ad click-to-Message',
                    default => 'Manual',
                };
            })
            ->implode(' • ');
    }

    private function describe(string $type): string
    {
        return match ($type) {
            'comment_to_dm' => 'Auto-DMs anyone who comments a keyword on your Reels & Feed posts.',
            'broadcast' => 'Sends a pre-approved WhatsApp template to your opted-in audience.',
            'ad_click_welcome' => 'Greets people the moment they tap your Click-to-Message ad.',
            default => 'Automated social funnel.',
        };
    }
}
