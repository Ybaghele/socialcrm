<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * PATCH /w/{workspace}/conversations/{conversation}
 *   { "status": "resolved" }   close the thread
 *   { "status": "open" }       reopen it
 */
class ConversationController extends Controller
{
    public function update(Request $request, string $workspace, Conversation $conversation): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,resolved'],
        ]);

        $resolved = $data['status'] === 'resolved';

        // forceFill: the status/resolution columns are intentionally not mass-assignable
        // through request input anywhere else in the app.
        $conversation->forceFill([
            'status' => $data['status'],
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $request->user()->id : null,
            'unread_count' => 0,
        ])->save();

        return back()->with('success', $resolved ? 'Thread marked as resolved.' : 'Thread reopened.');
    }
}
