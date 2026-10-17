<?php

namespace App\Services\Outbound\Drivers;

use App\Models\ChannelAccount;
use App\Models\ContactChannelIdentity;
use App\Models\Message;
use App\Services\Outbound\SendFailed;
use App\Services\Outbound\SendResult;

class WhatsAppDriver extends MetaDriver
{
    public function send(Message $message, ChannelAccount $account, ContactChannelIdentity $identity): SendResult
    {
        $base = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $identity->external_user_id];

        $payload = $message->type === 'template'
            ? $base + ['type' => 'template', 'template' => [
                'name' => $message->payload['template']['name'],
                'language' => ['code' => $message->payload['template']['language']],
                'components' => $message->payload['template']['components'] ?? [],
            ]]
            : $base + ['type' => 'text', 'text' => ['preview_url' => false, 'body' => (string) $message->body]];

        $response = $this->post($account, "{$account->external_id}/messages", $payload);

        $id = $response->json('messages.0.id');

        // Accepted but no id: don't retry (could double-send), surface it instead.
        if (! $id) {
            throw new SendFailed('Meta accepted the request but returned no message id.', retryable: false, errorCode: 'no_message_id');
        }

        return new SendResult((string) $id);
    }
}
