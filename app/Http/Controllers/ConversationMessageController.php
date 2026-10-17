<?php

namespace App\Http\Controllers;

use App\Actions\AddInternalNote;
use App\Actions\SendOutboundMessage;
use App\Exceptions\InsufficientBalance;
use App\Exceptions\OutboundNotAllowed;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /w/{workspace}/conversations/{conversation}/messages
 *   { "body": "Hi!" }                                          free-form reply (inside 24h)
 *   { "template_id": 7, "variables": ["Ananya", "#1042"] }     WhatsApp template
 *   { "body": "Called her, call back Fri", "note": true }      private internal note
 * {conversation} and template_id are tenant-scoped: another workspace's ids 404.
 * NB: don't type-hint Workspace here; the route param is a slug string.
 */
class ConversationMessageController
{
    public function store(Request $request, string $workspace, Conversation $conversation, SendOutboundMessage $send, AddInternalNote $note): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'],
            'template_id' => ['nullable', 'integer'],
            'variables' => ['array'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            'note' => ['boolean'],
        ]);

        if ($request->boolean('note')) {
            abort_if(blank($data['body'] ?? null), 422, 'A note cannot be empty.');
            $message = $note->handle($conversation, $request->user(), $data['body']);

            return response()->json(['id' => $message->id, 'status' => $message->status], 201);
        }

        $template = isset($data['template_id']) ? MessageTemplate::findOrFail($data['template_id']) : null;

        try {
            $message = $send->handle($conversation, $request->user(), $data['body'] ?? null, $template, $data['variables'] ?? []);
        } catch (OutboundNotAllowed $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e instanceof InsufficientBalance ? 402 : 422);
        }

        return response()->json(['id' => $message->id, 'status' => $message->status, 'cost_paise' => $message->cost_paise], 201);
    }
}
