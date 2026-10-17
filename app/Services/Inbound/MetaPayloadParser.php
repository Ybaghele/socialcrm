<?php

namespace App\Services\Inbound;

use App\Enums\ChannelType;
use App\Enums\MessageStatus;
use Carbon\CarbonImmutable;

/**
 * Turns Meta's three webhook shapes into plain DTOs.
 *  object = whatsapp_business_account  -> entry[].changes[].value.{messages,statuses}
 *  object = instagram | page           -> entry[].messaging[].message
 * Echo events (messages sent by the business from another tool) are ignored.
 */
final class MetaPayloadParser
{
    /** @return array{messages: list<InboundMessage>, statuses: list<StatusUpdate>} */
    public function parse(array $payload): array
    {
        $messages = [];
        $statuses = [];
        $object = $payload['object'] ?? null;

        foreach ($payload['entry'] ?? [] as $entry) {
            if ($object === 'whatsapp_business_account') {
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? null) !== 'messages') {
                        continue;
                    }
                    [$m, $s] = $this->parseWhatsApp($change['value'] ?? []);
                    array_push($messages, ...$m);
                    array_push($statuses, ...$s);
                }
            } elseif ($object === 'instagram' || $object === 'page') {
                $channel = $object === 'instagram' ? ChannelType::Instagram : ChannelType::Messenger;
                array_push($messages, ...$this->parseMessaging($channel, $entry));
            }
        }

        return ['messages' => $messages, 'statuses' => $statuses];
    }

    private function parseWhatsApp(array $value): array
    {
        $accountId = $value['metadata']['phone_number_id'] ?? null;
        if (! $accountId) {
            return [[], []];
        }

        $names = [];
        foreach ($value['contacts'] ?? [] as $contact) {
            $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
        }

        $messages = [];
        foreach ($value['messages'] ?? [] as $m) {
            $type = $m['type'] ?? 'text';
            $from = (string) ($m['from'] ?? '');

            $body = $m['text']['body']
                ?? $m['button']['text']
                ?? $m['interactive']['button_reply']['title']
                ?? $m['interactive']['list_reply']['title']
                ?? $m[$type]['caption']
                ?? null;

            $messages[] = new InboundMessage(
                channel: ChannelType::WhatsApp,
                accountExternalId: (string) $accountId,
                senderExternalId: $from,
                senderName: $names[$from] ?? null,
                senderPhone: $from !== '' ? '+'.$from : null,
                externalMessageId: (string) $m['id'],
                type: $type,
                body: $body,
                payload: $m,
                sentAt: isset($m['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $m['timestamp']) : CarbonImmutable::now(),
            );
        }

        $statuses = [];
        foreach ($value['statuses'] ?? [] as $s) {
            $status = MessageStatus::tryFrom($s['status'] ?? '');
            if (! $status) {
                continue;
            }
            $statuses[] = new StatusUpdate(
                channel: ChannelType::WhatsApp,
                accountExternalId: (string) $accountId,
                externalMessageId: (string) $s['id'],
                status: $status,
                at: isset($s['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $s['timestamp']) : CarbonImmutable::now(),
                error: $s['errors'][0] ?? null,
            );
        }

        return [$messages, $statuses];
    }

    private function parseMessaging(ChannelType $channel, array $entry): array
    {
        $messages = [];

        foreach ($entry['messaging'] ?? [] as $event) {
            $message = $event['message'] ?? null;
            if (! $message || ! empty($message['is_echo']) || empty($message['mid'])) {
                continue; // delivery/read receipts, echoes, postbacks: handled later
            }

            $accountId = $event['recipient']['id'] ?? $entry['id'] ?? null;
            $senderId = $event['sender']['id'] ?? null;
            if (! $accountId || ! $senderId) {
                continue;
            }

            $attachment = $message['attachments'][0]['type'] ?? null;

            $messages[] = new InboundMessage(
                channel: $channel,
                accountExternalId: (string) $accountId,
                senderExternalId: (string) $senderId,
                senderName: null, // needs a Graph API profile lookup; see SETUP.md
                senderPhone: null,
                externalMessageId: (string) $message['mid'],
                type: $attachment ?? 'text',
                body: $message['text'] ?? null,
                payload: $message,
                sentAt: isset($event['timestamp']) ? CarbonImmutable::createFromTimestampMs((int) $event['timestamp']) : CarbonImmutable::now(),
            );
        }

        return $messages;
    }
}
