<?php

namespace App\Http\Controllers;

use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use App\Models\Workspace;
use App\Services\Meta\TemplateSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET  /w/{workspace}/templates         the WhatsApp templates Meta has approved for this tenant
 * POST /w/{workspace}/templates/sync    pull the latest statuses from Meta
 *
 * Templates are authored and approved inside Meta, so this screen is a mirror —
 * the only write it performs is refreshing that mirror. It exists so a broadcast
 * author can see whether a template is usable before they pick it.
 */
class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $templates = MessageTemplate::query()
            ->with('channelAccount:id,name')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Templates', [
            'templates' => $templates->map(fn (MessageTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'language' => $template->language,
                'category' => $template->category,
                'status' => $template->status,
                'rejectionReason' => $template->rejection_reason,
                'channelAccount' => $template->channelAccount?->name,
                'channelAccountId' => $template->channel_account_id,
                'body' => $template->bodyText(),
                'variables' => $template->placeholderCount(),
            ])->all(),
            'whatsappAccounts' => ChannelAccount::query()
                ->where('channel', 'whatsapp')
                ->get(['id', 'name'])
                ->map(fn (ChannelAccount $account) => ['id' => $account->id, 'name' => $account->name])
                ->all(),
            'counts' => [
                'approved' => $templates->where('status', 'approved')->count(),
                'pending' => $templates->whereIn('status', ['pending', 'in_review', 'paused'])->count(),
                'rejected' => $templates->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function sync(Request $request, TemplateSync $sync): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        $result = $sync->sync($workspace);

        if ($result['errors'] !== []) {
            return back()->with('error', 'Template sync had trouble: '.implode(' ', $result['errors']));
        }

        if ($result['scanned'] === 0) {
            return back()->with('error', 'No templates found. Connect a WhatsApp number on the Channels screen first.');
        }

        return back()->with(
            'success',
            "Templates synced: {$result['imported']} new, {$result['updated']} refreshed.",
        );
    }
}
