<?php

namespace App\Actions;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Exceptions\OutboundNotAllowed;
use App\Jobs\DeliverOutboundMessage;
use App\Models\ContactChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Billing\WalletService;
use App\Services\Outbound\TemplateRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every rule about "may we send this?" lives here, BEFORE anything is queued or charged:
 *  - free-form text only inside the 24h window (WhatsApp outside it => must use a template)
 *  - Instagram/Messenger outside the window => blocked
 *  - templates: WhatsApp only, approved, belong to this number, marketing needs opt-in
 *  - template messages are charged to the wallet in the same transaction as the message row
 * Call inside a tenant context (request middleware / Tenancy::run).
 */
class SendOutboundMessage
{
    public function __construct(private WalletService $wallet)
    {
    }

    /**
     * A null $author means the workspace itself is sending (an automation), which
     * is recorded as a bot message with no user_id. Two things deliberately differ
     * for a bot: it does not claim an unassigned thread, and it does not clear the
     * unread badge or set first_response_at — an agent still needs to look at the
     * thread, and "time to first human reply" must stay honest.
     */
    public function handle(Conversation $conversation, ?User $author, ?string $text = null, ?MessageTemplate $template = null, array $variables = []): Message
    {
        $channel = ChannelType::from($conversation->channel);
        $account = $conversation->channelAccount;
        $contact = $conversation->contact;

        if (! $account || $account->status !== 'connected') {
            throw new OutboundNotAllowed('channel_disconnected', 'This channel is disconnected. Reconnect it in Settings.');
        }

        $identity = ContactChannelIdentity::where('channel_account_id', $account->id)->where('contact_id', $contact->id)->first();
        if (! $identity) {
            throw new OutboundNotAllowed('no_identity', 'This contact has no address on this channel.');
        }

        [$type, $body, $payload, $cost] = $template
            ? $this->planTemplate($conversation, $channel, $account->id, $contact->is_opted_in, $template, $variables)
            : $this->planText($conversation, $channel, (string) $text);

        $message = DB::transaction(function () use ($conversation, $author, $template, $type, $body, $payload, $cost) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound->value,
                'sender_type' => $author ? SenderType::User->value : SenderType::Bot->value,
                'user_id' => $author?->id,
                'message_template_id' => $template?->id,
                'type' => $type,
                'body' => $body,
                'payload' => $payload,
                'status' => MessageStatus::Queued->value,
                'cost_paise' => $cost,
            ]);

            if ($cost > 0) {
                // Throws InsufficientBalance => whole transaction rolls back, nothing is queued.
                $this->wallet->debit($conversation->workspace, $cost, 'template_message', $message, 'WhatsApp template message');
            }

            $conversation->forceFill([
                'last_message_preview' => Str::limit($body, 140),
                'last_message_at' => now(),
                'first_response_at' => $author ? ($conversation->first_response_at ?? now()) : $conversation->first_response_at,
                'assigned_user_id' => $conversation->assigned_user_id ?? $author?->id, // replying claims an unassigned thread
                'unread_count' => $author ? 0 : $conversation->unread_count,
            ])->save();

            return $message;
        });

        DeliverOutboundMessage::dispatch($message->id, $conversation->workspace_id)->afterCommit();

        return $message;
    }

    /** @return array{0: string, 1: string, 2: ?array, 3: int} */
    private function planText(Conversation $conversation, ChannelType $channel, string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new OutboundNotAllowed('empty_message', 'Type a message first.');
        }

        $max = (int) config("omniflow.max_text_length.{$channel->value}", 1000);
        if (mb_strlen($text) > $max) {
            throw new OutboundNotAllowed('message_too_long', "Messages on {$channel->value} are limited to {$max} characters.");
        }

        if (! $conversation->isWithinReplyWindow()) {
            throw $channel === ChannelType::WhatsApp
                ? new OutboundNotAllowed('template_required', 'The 24-hour reply window has closed. Send an approved template message instead.')
                : new OutboundNotAllowed('window_closed', 'The 24-hour reply window has closed, so you can no longer message this person on this channel.');
        }

        return ['text', $text, null, 0];
    }

    /** @return array{0: string, 1: string, 2: ?array, 3: int} */
    private function planTemplate(Conversation $conversation, ChannelType $channel, int $accountId, bool $optedIn, MessageTemplate $template, array $variables): array
    {
        if ($channel !== ChannelType::WhatsApp) {
            throw new OutboundNotAllowed('template_unsupported', 'Template messages are only available on WhatsApp.');
        }

        if ($template->channel_account_id !== $accountId) {
            throw new OutboundNotAllowed('template_wrong_account', 'That template belongs to a different WhatsApp number.');
        }

        if ($template->status !== 'approved') {
            throw new OutboundNotAllowed('template_not_approved', 'That template has not been approved by Meta yet.');
        }

        if ($template->category === 'marketing' && ! $optedIn) {
            throw new OutboundNotAllowed('opted_out', 'This contact has opted out of marketing messages.');
        }

        $expected = TemplateRenderer::placeholderCount($template->components);
        if (count($variables) !== $expected) {
            throw new OutboundNotAllowed('template_variables', "This template needs {$expected} variable(s); ".count($variables).' given.');
        }

        $cost = (int) config("omniflow.pricing.whatsapp_template_paise.{$template->category}", 0);

        return [
            'template',
            TemplateRenderer::render($template->components, $variables),
            ['template' => [
                'name' => $template->name,
                'language' => $template->language,
                'components' => TemplateRenderer::sendComponents($template->components, $variables),
            ]],
            $cost,
        ];
    }
}
