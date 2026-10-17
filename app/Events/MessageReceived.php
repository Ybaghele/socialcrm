<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Pushed to the tenant's private Reverb channel so the inbox updates live. Scalars only: no models. */
class MessageReceived implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $workspaceId,
        public int $conversationId,
        public int $messageId,
        public string $channel,
        public string $preview,
        public bool $newContact,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("workspace.{$this->workspaceId}.inbox")];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }
}
