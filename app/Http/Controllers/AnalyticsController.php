<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WalletTransaction;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Analytics screen: a windowed, read-only summary of messaging, audience and
 * spend. Everything is computed from existing rows (messages, conversations,
 * contacts, wallet_transactions) so there is no reporting table to keep in sync.
 *
 * Date grouping uses DATE(created_at), which MySQL and SQLite both support.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        $ranges = array_values((array) config('omniflow.analytics.ranges', [7, 30, 90]));
        $default = (int) config('omniflow.analytics.default_range', 30);
        $requested = (int) $request->integer('range', $default);
        $days = in_array($requested, $ranges, true) ? $requested : $default;

        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        return Inertia::render('Analytics', [
            'range' => $days,
            'ranges' => $ranges,
            'totals' => $this->totals($workspace, $from),
            'series' => $this->series($workspace, $from, $days),
            'channels' => $this->channels($workspace, $from),
            'campaigns' => $this->topCampaigns($workspace),
        ]);
    }

    /** @return array<string, mixed> */
    private function totals(Workspace $workspace, CarbonImmutable $from): array
    {
        $inbound = $this->messages($workspace, $from)->where('direction', 'inbound')->count();
        $outbound = $this->messages($workspace, $from)->where('direction', 'outbound')->count();

        return [
            'inbound' => $inbound,
            'outbound' => $outbound,
            'broadcastSends' => $this->messages($workspace, $from)->whereNotNull('campaign_id')->count(),
            'conversations' => Conversation::query()
                ->where('workspace_id', $workspace->id)->where('created_at', '>=', $from)->count(),
            'newContacts' => Contact::query()
                ->where('workspace_id', $workspace->id)->where('created_at', '>=', $from)->count(),
            'optedIn' => Contact::query()
                ->where('workspace_id', $workspace->id)->where('is_opted_in', true)->count(),
            'activeConversations' => Conversation::query()
                ->where('workspace_id', $workspace->id)->where('status', 'open')->count(),
            // WalletTransaction has no tenant scope, so it is filtered explicitly.
            'spendPaise' => (int) WalletTransaction::query()
                ->where('workspace_id', $workspace->id)
                ->where('type', 'debit')
                ->where('reason', 'template_message')
                ->where('created_at', '>=', $from)
                ->sum('amount_paise'),
            'replyRate' => $outbound > 0 ? round($inbound / $outbound * 100, 1) : 0.0,
            'avgFirstResponseSeconds' => $this->averageFirstResponse($workspace, $from),
        ];
    }

    /**
     * One row per day in the window, zero-filled, ready to chart.
     *
     * @return array<int, array<string, mixed>>
     */
    private function series(Workspace $workspace, CarbonImmutable $from, int $days): array
    {
        $byDay = [];

        $messageRows = $this->messages($workspace, $from)
            ->selectRaw('DATE(created_at) as day, direction, count(*) as total')
            ->groupByRaw('DATE(created_at), direction')
            ->get();

        foreach ($messageRows as $row) {
            $byDay[(string) $row->day][(string) $row->direction] = (int) $row->total;
        }

        $contactRows = Contact::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->pluck('total', 'day');

        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->addDays($offset);
            $key = $date->toDateString();

            $series[] = [
                'day' => $key,
                'label' => $date->format('j M'),
                'inbound' => (int) ($byDay[$key]['inbound'] ?? 0),
                'outbound' => (int) ($byDay[$key]['outbound'] ?? 0),
                'contacts' => (int) ($contactRows[$key] ?? 0),
            ];
        }

        return $series;
    }

    /** @return array<int, array<string, mixed>> */
    private function channels(Workspace $workspace, CarbonImmutable $from): array
    {
        $rows = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $from)
            ->selectRaw('channel, count(*) as total')
            ->groupBy('channel')
            ->orderByDesc('total')
            ->get();

        $sum = max(1, (int) $rows->sum('total'));

        return $rows->map(fn ($row): array => [
            'channel' => (string) $row->channel,
            'total' => (int) $row->total,
            'percent' => (int) round($row->total / $sum * 100),
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function topCampaigns(Workspace $workspace): array
    {
        return Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'broadcast')
            ->orderByDesc('delivered_count')
            ->limit(5)
            ->get(['id', 'name', 'status', 'enrolled_count', 'delivered_count', 'replied_count', 'won_count'])
            ->map(fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'enrolled' => (int) $campaign->enrolled_count,
                'delivered' => (int) $campaign->delivered_count,
                'replied' => (int) $campaign->replied_count,
                'won' => (int) $campaign->won_count,
            ])
            ->all();
    }

    /** Median-free simple mean of (first response - conversation created), in seconds. */
    private function averageFirstResponse(Workspace $workspace, CarbonImmutable $from): ?int
    {
        $conversations = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('first_response_at')
            ->where('created_at', '>=', $from)
            ->limit(500)
            ->get(['created_at', 'first_response_at']);

        if ($conversations->isEmpty()) {
            return null;
        }

        $total = $conversations->sum(
            fn (Conversation $conversation): float => max(0, $conversation->created_at->diffInSeconds($conversation->first_response_at)),
        );

        return (int) round($total / $conversations->count());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Message> */
    private function messages(Workspace $workspace, CarbonImmutable $from)
    {
        return Message::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $from);
    }
}
