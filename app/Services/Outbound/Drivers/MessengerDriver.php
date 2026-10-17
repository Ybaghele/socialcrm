<?php

namespace App\Services\Outbound\Drivers;

use App\Models\ChannelAccount;
use App\Models\ContactChannelIdentity;
use App\Models\Message;
use App\Services\Outbound\SendFailed;
use App\Services\Outbound\SendResult;

/** Facebook Messenger and Instagram DMs share the Send API: POST /me/messages with the account's token. */
class MessengerDriver extends MetaDriver
{
    public function send(Message $message, ChannelAccount $account, ContactChannelIdentity $identity): SendResult
    {
        $response = $this->post($account, 'me/messages', [
            'recipient' => ['id' => $identity->external_user_id],
            'messaging_type' => 'RESPONSE', // inside the 24h window only; outside it needs the HUMAN_AGENT tag (not built yet)
            'message' => ['text' => (string) $message->body],
        ]);

        $id = $response->json('message_id');

        if (! $id) {
            throw new SendFailed('Meta accepted the request but returned no message id.', retryable: false, errorCode: 'no_message_id');
        }

        return new SendResult((string) $id);
    }
}
