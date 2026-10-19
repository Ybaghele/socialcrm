<?php

namespace App\Jobs\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\Contact;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableTrait;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The heartbeat of a broadcast. Run every minute by `campaigns:process` on the
 * scheduler, it does three things per active broadcast, always inside the owning
 * tenant's context:
 *
 *   1. enrol opted-in contacts that are not in the campaign yet (in chunks);
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
            ->where('status', 'active')
            ->where('type', 'broadcast')
            ->get();

        foreach ($campaigns as $campaign) {
            $workspace = Workspace::find($campaign->workspace_id);

            if (! $workspace) {
                continue;
            }

            Tenancy::run($workspace, function () use ($campaign) {
                $this->enrollAudience($campaign);
                $this->dispatchPending($campaign);
                $this->completeIfFinished($campaign);
            });
        }
    }

    /** Add the next chunk of opted-in contacts to the campaign. */
    private function enrollAudience(Campaign $campaign): void
    {
        $limit = (int) config('omniflow.campaigns.enroll_batch', 500);

        $contacts = Contact::query()
            ->where('is_opted_in', true)
            ->whereDoesntHave('enrollments', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

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
    private function completeIfFinished(Campaign $campaign): void
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

        $moreToEnroll = Contact::query()
            ->where('is_opted_in', true)
            ->whereDoesntHave('enrollments', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->exists();

        if ($moreToEnroll) {
            return;
        }

        $campaign->update(['status' => 'completed', 'ended_at' => now()]);
    }
}
