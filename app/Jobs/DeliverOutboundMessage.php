<?php

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Enums\MessageStatus;
use App\Events\MessageUpdated;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ContactChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Billing\WalletService;
use App\Services\Outbound\DriverManager;
use App\Services\Outbound\SendFailed;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands one queued message to Meta. IDs only (see TenantAwareJob).
 * Safe to retry: it never re-sends a message that already has a provider id.
 * Any permanent failure marks the message failed AND refunds its wallet charge.
 */
class DeliverOutboundMessage implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 4;

    public function __construct(public int $messageId, int $workspaceId)
    {
        $this->forWorkspace($workspaceId);
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(DriverManager $drivers, WalletService $wallet): void
    {
        $message = Message::find($this->messageId);

        // Already sent / already failed / vanished: nothing to do (idempotent).
        if (! $message || $message->external_id || $message->status !== MessageStatus::Queued->value) {
            return;
        }

        $conversation = Conversation::findOrFail($message->conversation_id);
        $account = $conversation->channelAccount;
        $identity = ContactChannelIdentity::where('channel_account_id', $conversation->channel_account_id)
            ->where('contact_id', $conversation->contact_id)->first();

        if (! $account || ! $identity) {
            $this->markFailed($message, $wallet, 'no_destination', 'Channel account or contact address no longer exists.');

            return;
        }

        try {
            $result = $drivers->for(ChannelType::from($conversation->channel))->send($message, $account, $identity);
        } catch (SendFailed $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                throw $e; // queue retries with backoff
            }

            $this->markFailed($message, $wallet, $e->errorCode ?? 'send_failed', $e->getMessage());

            return;
        }

        $message->update(['external_id' => $result->externalId, 'status' => MessageStatus::Sent->value, 'sent_at' => now()]);
        event(new MessageUpdated($message->workspace_id, $message->conversation_id, $message->id, MessageStatus::Sent->value));
    }

    /** Retries exhausted, or an unexpected exception. Runs outside job middleware, so re-enter the tenant. */
    public function failed(Throwable $e): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if (! $workspace) {
            return;
        }

        Tenancy::run($workspace, function () use ($e) {
            $message = Message::find($this->messageId);

            if ($message && $message->status === MessageStatus::Queued->value) {
                $code = $e instanceof SendFailed ? ($e->errorCode ?? 'send_failed') : 'send_failed';
                $this->markFailed($message, app(WalletService::class), $code, $e->getMessage());
            }
        });
    }

    private function markFailed(Message $message, WalletService $wallet, string $code, string $reason): void
    {
        $message->update(['status' => MessageStatus::Failed->value, 'error_code' => $code, 'error_message' => Str::limit($reason, 500)]);
        $wallet->refundMessage($message);
        event(new MessageUpdated($message->workspace_id, $message->conversation_id, $message->id, MessageStatus::Failed->value));
    }
}
