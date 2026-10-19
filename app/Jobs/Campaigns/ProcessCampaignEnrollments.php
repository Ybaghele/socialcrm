<?php

namespace App\Jobs\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\Audiences\SegmentQuery;
use App\Services\Notifications\WorkspaceNotifier;
use App\Support\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableTrait;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The heartbeat of a broadcast. Run every minute by `campaigns:process` on the
 * scheduler, it does three things per active (or now-due scheduled) broadcast,
 * always inside the owning tenant's context:
 *
 *   1. enrol the segment audience (opted-in only) not yet in the campaign;
 *   2. hand a bounded batch of pending enrolments to the delivery queue;
 *   3. mark the campaign completed once the whole audience has been sent to.
 *
 * Bounding each step is what keeps a 50k-contact broadcast from melting the
 * shared-hosting queue in a single tick.
 */
class ProcessCampaignEnrollments implements ShouldQueue
{
    use InteractsWithQueue, QueueableTrait, SerializesModels, Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $campaigns = Campaign::withoutGlobalScopes()
            ->whereIn('status', ['active', 'scheduled'])
            ->where('type', 'broadcast')
            ->get();

        foreach ($campaigns as $campaign) {
            $workspace = Workspace::find($campaign->workspace_id);

            if (! $workspace) {
                continue;
            }

            Tenancy::run($workspace, function () use ($campaign, $workspace) {
                // A scheduled broadcast sleeps until its moment arrives.
                if (! $this->isDue($campaign)) {
                    return;
                }

                // A broadcast with no approved template (or a disconnected number)
                // cannot send anything, so leave it exactly as the operator left it
                // instead of enrolling contacts just to fail them.
                if (! $this->isReady($campaign)) {
                    return;
                }

                // The moment is here: a scheduled broadcast becomes live.
                $this->activateIfScheduled($campaign);

                $this->enrollAudience($campaign);
                $this->dispatchPending($campaign);
                $this->completeIfFinished($campaign, $workspace);
            });
        }
    }

    /** A scheduled broadcast sleeps until its moment arrives. */
    private function isDue(Campaign $campaign): bool
    {
        return $campaign->status !== 'scheduled'
            || $campaign->scheduled_at === null
            || $campaign->scheduled_at->isPast();
    }

    /** The first tick after a scheduled broadcast comes due flips it live. */
    private function activateIfScheduled(Campaign $campaign): void
    {
        if ($campaign->status === 'scheduled') {
            $campaign->update(['status' => 'active', 'started_at' => now()]);
        }
    }

    /**
     * A campaign can only be processed once it has a connected number and an
     * approved template that belongs to that number.
     */
    private function isReady(Campaign $campaign): bool
    {
        $account = $campaign->channelAccount;

        if (! $account || $account->status !== 'connected') {
            return false;
        }

        $template = $campaign->template;

        return $template !== null
            && $template->isApproved()
            && $template->channel_account_id === $account->id;
    }

    /** Add the next chunk of opted-in contacts to the campaign. */
    private function enrollAudience(Campaign $campaign): void
    {
        $limit = (int) config('omniflow.campaigns.enroll_batch', 500);

        $contacts = SegmentQuery::for($campaign->segment)
            ->whereDoesntHave('enrollments', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->orderBy('contacts.id')
            ->limit($limit)
            ->get(['contacts.id']);

        if ($contacts->isEmpty()) {
            return;
        }

        $now = now();

        // insertOrIgnore leans on the unique (campaign_id, contact_id) index so a
        // re-run is a no-op without a SELECT per contact.
        CampaignEnrollment::insertOrIgnore(
            $contacts->map(fn (Contact $contact) => [
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'active',
                'current_step' => 0,
                'enrolled_at' => $now,
                'next_run_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );

        $campaign->update(['enrolled_count' => $campaign->enrollments()->count()]);
    }

    /** Queue a bounded batch of enrolled contacts for delivery. */
    private function dispatchPending(Campaign $campaign): void
    {
        $limit = (int) config('omniflow.campaigns.send_batch', 50);
        $hold = now()->addMinutes((int) config('omniflow.campaigns.retry_after_minutes', 15));

        $pending = $campaign->enrollments()
            ->where('status', 'active')
            ->whereNull('delivered_at')
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pending as $enrollment) {
            // Park it so the next minute's run does not double-queue it while the
            // worker is still going. A completed send sets delivered_at; one that
            // is stuck becomes eligible again after the hold.
            $enrollment->update(['next_run_at' => $hold]);

            DeliverCampaignBroadcast::dispatch($enrollment->id);
        }
    }

    /** Close the campaign once everyone has been attempted. */
    private function completeIfFinished(Campaign $campaign, Workspace $workspace): void
    {
        // Nothing enrolled yet (e.g. an empty audience) — stay live so contacts
        // who opt in later are still picked up.
        if ((int) $campaign->enrolled_count === 0) {
            return;
        }

        $stillPending = $campaign->enrollments()
            ->where('status', 'active')
            ->whereNull('delivered_at')
            ->exists();

        if ($stillPending) {
            return;
        }

        $moreToEnroll = SegmentQuery::for($campaign->segment)
            ->whereDoesntHave('enrollments', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->exists();

        if ($moreToEnroll) {
            return;
        }

        $campaign->update(['status' => 'completed', 'ended_at' => now()]);

        app(WorkspaceNotifier::class)->managers(
            $workspace,
            'campaign_completed',
            "Broadcast “{$campaign->name}” finished",
            $campaign->delivered_count.' delivered · '.$campaign->replied_count.' replied · '.$campaign->won_count.' won.',
            "/w/{$workspace->slug}/campaigns",
            'success',
        );
    }
}
