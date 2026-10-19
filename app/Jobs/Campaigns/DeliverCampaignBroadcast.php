<?php

namespace App\Jobs\Campaigns;

use App\Actions\SendOutboundMessage;
use App\Exceptions\InsufficientBalance;
use App\Exceptions\OutboundNotAllowed;
use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableTrait;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one broadcast message to one enrolled contact.
 *
 * It deliberately reuses {@see SendOutboundMessage} rather than calling a driver
 * directly: that action owns every "may we send this?" rule (approved template,
 * template belongs to this number, marketing consent), the wallet debit in the
 * same transaction as the message row, and the queued Meta delivery. So a
 * broadcast behaves exactly like a manual template reply — it even lands in the
 * contact's inbox thread — and the two paths can never drift apart.
 */
class DeliverCampaignBroadcast implements ShouldQueue
{
    use InteractsWithQueue, QueueableTrait, SerializesModels, Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public int $enrollmentId)
    {
    }

    public function handle(SendOutboundMessage $send): void
    {
        $enrollment = CampaignEnrollment::find($this->enrollmentId);

        // Already handled (or cancelled while queued) — nothing to do.
        if (! $enrollment || $enrollment->status !== 'active' || $enrollment->delivered_at) {
            return;
        }

        $campaign = Campaign::withoutGlobalScopes()->find($enrollment->campaign_id);
        $workspace = $campaign ? Workspace::find($campaign->workspace_id) : null;

        if (! $campaign || ! $workspace || $campaign->status !== 'active') {
            return;
        }

        Tenancy::run($workspace, function () use ($enrollment, $campaign, $workspace, $send) {
            $contact = Contact::find($enrollment->contact_id);

            if (! $contact) {
                $this->markFailed($enrollment, 'The contact was deleted.');

                return;
            }

            $account = $campaign->channelAccount;

            if (! $account || $account->status !== 'connected') {
                $this->markFailed($enrollment, 'The sending WhatsApp number is disconnected.');

                return;
            }

            $template = $campaign->template;

            if (! $template || ! $template->isApproved()) {
                $this->markFailed($enrollment, 'The WhatsApp template is not approved by Meta yet.');

                return;
            }

            $conversation = $this->conversationFor($campaign, $contact);

            try {
                $message = $send->handle(
                    $conversation,
                    $this->author($campaign, $workspace),
                    null,
                    $template,
                    $this->variables($campaign, $contact, $template),
                );
            } catch (InsufficientBalance $e) {
                // Out of wallet: pause the campaign so we stop hammering Meta, and
                // leave this contact's enrollment for when the tenant tops up.
                $campaign->update(['status' => 'paused']);
                $this->markFailed($enrollment, $e->getMessage());

                return;
            } catch (OutboundNotAllowed $e) {
                $this->markFailed($enrollment, $e->getMessage());

                return;
            }

            // Attribute the send to the campaign (the action does not know about it).
            $message->forceFill(['campaign_id' => $campaign->id])->save();

            $enrollment->update([
                'status' => 'completed',
                'delivered_at' => now(),
                'completed_at' => now(),
            ]);

            $campaign->increment('delivered_count');
        });
    }

    /** Last resort when the job dies after exhausting its retries. */
    public function failed(?Throwable $e): void
    {
        $enrollment = CampaignEnrollment::find($this->enrollmentId);

        if ($enrollment && $enrollment->status === 'active' && ! $enrollment->delivered_at) {
            $this->markFailed($enrollment, $e?->getMessage() ?? 'Delivery job failed.');
        }
    }

    /** Reuse the existing thread if there is one, otherwise open a new inbox thread. */
    private function conversationFor(Campaign $campaign, Contact $contact): Conversation
    {
        return Conversation::firstOrCreate(
            [
                'contact_id' => $contact->id,
                'channel_account_id' => $campaign->channel_account_id,
            ],
            [
                'channel' => $campaign->channel,
                'status' => 'open',
            ],
        );
    }

    private function author(Campaign $campaign, Workspace $workspace): User
    {
        $author = $campaign->creator ?? $workspace->owner;

        // User is a hard foreign key on messages; fall back to any workspace member.
        return $author ?? $workspace->users()->firstOrFail();
    }

    /** @return array<int, string> */
    private function variables(Campaign $campaign, Contact $contact, MessageTemplate $template): array
    {
        $stored = $campaign->template_variables ?? [];
        $count = $template->placeholderCount();

        $variables = [];

        for ($i = 0; $i < $count; $i++) {
            $variables[] = $this->expand((string) ($stored[$i] ?? ''), $contact);
        }

        return $variables;
    }

    /**
     * Fill a stored value with per-contact merge tags. An empty slot falls back to
     * the contact's first name so Meta never receives a blank parameter.
     */
    private function expand(string $value, Contact $contact): string
    {
        if (trim($value) === '') {
            return $this->firstName($contact);
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', function (array $match) use ($contact): string {
            return match (strtolower($match[1])) {
                'name', 'full_name' => (string) $contact->name,
                'first_name' => $this->firstName($contact),
                'phone', 'mobile' => (string) ($contact->phone ?? ''),
                'email' => (string) ($contact->email ?? ''),
                'city' => (string) ($contact->city ?? ''),
                default => (string) $match[0],
            };
        }, $value);
    }

    private function firstName(Contact $contact): string
    {
        $name = trim((string) $contact->name);

        return $name === '' ? 'there' : explode(' ', $name)[0];
    }

    private function markFailed(CampaignEnrollment $enrollment, string $reason): void
    {
        $enrollment->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);

        Log::warning('Campaign broadcast skipped', [
            'enrollment_id' => $enrollment->id,
            'campaign_id' => $enrollment->campaign_id,
            'reason' => $reason,
        ]);
    }
}
