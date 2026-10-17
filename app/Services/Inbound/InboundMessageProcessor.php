<?php

namespace App\Services\Inbound;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Services\Billing\WalletService;
use App\Models\ActivityLog;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\ContactChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only place that turns an inbound provider event into contact + conversation + message.
 * Flow: external account id -> workspace -> (switch tenant) -> store, all in one transaction.
 * Idempotent: replaying the same provider message id is a no-op.
 */
final class InboundMessageProcessor
{
    public function __construct(private RoundRobinAssigner $router, private WalletService $wallet)
    {
    }

    public function handle(InboundMessage $in): ?Message
    {
        $account = $this->findAccount($in->channel->value, $in->accountExternalId);
        $workspace = $account ? Workspace::find($account->workspace_id) : null;

        if (! $account || ! $workspace) {
            Log::warning('Inbound message for unknown channel account', ['channel' => $in->channel->value, 'account' => $in->accountExternalId]);

            return null;
        }

        // Suspended / expired tenants are not processed (the raw payload stays in webhook_events).
        if (! $workspace->hasAccess()) {
            return null;
        }

        return Tenancy::run($workspace, fn () => DB::transaction(fn () => $this->store($workspace, $account, $in)));
    }

    public function applyStatus(StatusUpdate $update): bool
    {
        $account = $this->findAccount($update->channel->value, $update->accountExternalId);
        $workspace = $account ? Workspace::find($account->workspace_id) : null;

        if (! $workspace) {
            return false;
        }

        return Tenancy::run($workspace, function () use ($update) {
            $message = Message::where('external_id', $update->externalMessageId)->first();
            if (! $message) {
                return false;
            }

            if ($update->status === MessageStatus::Failed) {
                $message->update([
                    'status' => MessageStatus::Failed->value,
                    'error_code' => isset($update->error['code']) ? (string) $update->error['code'] : null,
                    'error_message' => $update->error['title'] ?? $update->error['message'] ?? null,
                ]);

                // Meta does not charge for undelivered template messages: give the wallet charge back (once).
                $this->wallet->refundMessage($message->fresh());
                event(new MessageUpdated($message->workspace_id, $message->conversation_id, $message->id, MessageStatus::Failed->value));

                return true;
            }

            $current = MessageStatus::tryFrom($message->status) ?? MessageStatus::Sent;
            if ($update->status->rank() <= $current->rank()) {
                return true; // out-of-order receipt: never move backwards
            }

            $message->forceFill(['status' => $update->status->value]);
            match ($update->status) {
                MessageStatus::Sent => $message->sent_at ??= $update->at,
                MessageStatus::Delivered => $message->delivered_at = $update->at,
                MessageStatus::Read => [$message->delivered_at ??= $update->at, $message->read_at = $update->at],
                default => null,
            };
            $message->save();
            event(new MessageUpdated($message->workspace_id, $message->conversation_id, $message->id, $update->status->value));

            return true;
        });
    }

    private function findAccount(string $channel, string $externalId): ?ChannelAccount
    {
        // The one legitimate cross-tenant lookup: we don't know the tenant until we find the account.
        // A disconnected account must stop ingesting (see ChannelController::update). A token
        // "error", by contrast, only affects outbound sending, so inbound keeps flowing.
        return Tenancy::runUnscoped(fn () => ChannelAccount::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('status', '!=', 'disconnected')
            ->first());
    }

    private function store(Workspace $workspace, ChannelAccount $account, InboundMessage $in): ?Message
    {
        if (Message::where('external_id', $in->externalMessageId)->exists()) {
            return null; // webhook retry
        }

        [$contact, $isNewContact] = $this->resolveContact($workspace, $account, $in);

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'channel_account_id' => $account->id],
            ['channel' => $in->channel->value, 'status' => ConversationStatus::Open->value, 'assigned_user_id' => $contact->assigned_user_id],
        );

        $preview = $in->body ?? "[{$in->type}]";
        $wasResolved = $conversation->status === ConversationStatus::Resolved->value;

        $conversation->forceFill([
            'status' => ConversationStatus::Open->value, // a new inbound reopens resolved threads
            'resolved_at' => null,
            'resolved_by' => null,
            'last_message_preview' => Str::limit($preview, 140),
            'last_message_at' => $in->sentAt,
            'last_inbound_at' => $in->sentAt,
        ])->save();
        $conversation->increment('unread_count');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound->value,
            'sender_type' => SenderType::Contact->value,
            'type' => $in->type,
            'body' => $in->body,
            'payload' => $in->payload,
            'external_id' => $in->externalMessageId,
            'status' => MessageStatus::Delivered->value,
            'sent_at' => $in->sentAt,
        ]);

        $contactUpdates = ['last_interaction_at' => $in->sentAt];
        if ($in->body && preg_match('/^\s*(stop|unsubscribe)\s*$/i', $in->body)) {
            $contactUpdates += ['is_opted_in' => false, 'opted_out_at' => now()];
            ActivityLog::create(['contact_id' => $contact->id, 'event' => 'opted_out', 'description' => 'Contact opted out of marketing messages']);
        }
        $contact->forceFill($contactUpdates)->save();

        if ($wasResolved) {
            ActivityLog::create(['contact_id' => $contact->id, 'event' => 'conversation_reopened', 'description' => 'Conversation reopened by new inbound message', 'subject_type' => Conversation::class, 'subject_id' => $conversation->id]);
        }

        PlanLimits::for($workspace)->recordActiveContact($contact);

        event(new MessageReceived(
            workspaceId: $workspace->id,
            conversationId: $conversation->id,
            messageId: $message->id,
            channel: $in->channel->value,
            preview: Str::limit($preview, 140),
            newContact: $isNewContact,
        ));

        return $message;
    }

    /** @return array{0: Contact, 1: bool} [contact, wasJustCreated] */
    private function resolveContact(Workspace $workspace, ChannelAccount $account, InboundMessage $in): array
    {
        $identity = ContactChannelIdentity::where('channel_account_id', $account->id)
            ->where('external_user_id', $in->senderExternalId)
            ->first();

        if ($identity) {
            $contact = Contact::withTrashed()->findOrFail($identity->contact_id);
            $contact->trashed() && $contact->restore();
            $identity->update(['last_inbound_at' => $in->sentAt]);

            return [$contact, false];
        }

        // Same phone number already known from another channel? Merge into that contact.
        $contact = $in->senderPhone
            ? Contact::withTrashed()->where('phone', $in->senderPhone)->first()
            : null;
        $isNew = $contact === null;

        if ($contact) {
            $contact->trashed() && $contact->restore();
        } else {
            $contact = Contact::create([
                'name' => $in->senderName ?: ($in->senderPhone ?? ucfirst($in->channel->value).' user '.substr($in->senderExternalId, -4)),
                'phone' => $in->senderPhone,
                'source_channel' => $in->channel->value,
                'pipeline_stage_id' => $workspace->pipelineStages()->where('is_default', true)->value('id'),
                'assigned_user_id' => $this->router->next($workspace)?->id,
            ]);

            ActivityLog::create(['contact_id' => $contact->id, 'event' => 'contact_created', 'description' => "New lead captured via {$in->channel->value}", 'properties' => ['channel' => $in->channel->value]]);

            if ($contact->assigned_user_id) {
                ActivityLog::create(['contact_id' => $contact->id, 'event' => 'assigned', 'description' => 'Assigned by round-robin inbound router', 'properties' => ['assigned_user_id' => $contact->assigned_user_id]]);
            }
        }

        ContactChannelIdentity::create([
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'channel' => $in->channel->value,
            'external_user_id' => $in->senderExternalId,
            'last_inbound_at' => $in->sentAt,
        ]);

        return [$contact, $isNew];
    }
}
