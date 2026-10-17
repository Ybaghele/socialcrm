<?php

namespace App\Actions;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/** Private staff note in the thread. Never sent to the customer, never billed. */
class AddInternalNote
{
    public function handle(Conversation $conversation, User $author, string $note): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Internal->value,
            'sender_type' => SenderType::User->value,
            'user_id' => $author->id,
            'type' => 'text',
            'body' => trim($note),
            'status' => MessageStatus::Sent->value,
            'sent_at' => now(),
        ]);
    }
}
