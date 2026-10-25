<?php

namespace App\Http\Controllers;

use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use App\Models\Workspace;
use App\Services\Meta\TemplateCreator;
use App\Services\Meta\TemplateSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request, TemplateCreator $creator): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace instanceof Workspace, 404);

        $role = $request->attributes->get('workspace_role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);

        $validated = $request->validate([
            'channel_account_id' => ['required', 'integer', Rule::exists('channel_accounts', 'id')->where(fn ($q) => $q->where('channel', 'whatsapp')->where('status', 'connected'))],
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'category' => ['required', Rule::in(['marketing', 'utility', 'authentication', 'MARKETING', 'UTILITY', 'AUTHENTICATION'])],
            'language' => ['required', 'string', 'max:10'],
            'components' => ['required', 'array'],
        ]);

        $account = ChannelAccount::findOrFail((int) $validated['channel_account_id']);
        abort_unless((int) $account->workspace_id === (int) $workspace->getKey(), 403);

        $existing = MessageTemplate::query()
            ->where('channel_account_id', $account->id)
            ->where('name', $validated['name'])
            ->where('language', $validated['language'])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'name' => 'A template with this name and language already exists for this WhatsApp account.',
            ]);
        }

        $payload = [
            'name' => $validated['name'],
            'category' => strtoupper($validated['category']),
            'language' => $validated['language'],
            'components' => $validated['components'],
        ];
        try {
            $meta = $creator->create($account, $payload);
            
            // Check if Meta rejected it without throwing a hard exception
            if (isset($meta['status']) && $meta['status'] === 'failed') {
                $errorMessage = $meta['error']['error']['message'] 
                    ?? $meta['error']['message'] 
                    ?? 'Unknown validation rejection from Meta API.';
                    
                return back()->with('error', 'Meta rejected this template: ' . $errorMessage);
            }
            
        } catch (ConnectionException $e) {
            return back()->with('error', 'Could not reach Meta: '.$e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->with('error', 'Meta rejected this template: '.$creator->reason($e));
        }


        MessageTemplate::create([
            'workspace_id' => $workspace->getKey(),
            'channel_account_id' => $account->id,
            'name' => $payload['name'],
            'language' => $payload['language'],
            'category' => strtolower($payload['category']),
            'status' => $meta['status'] ?: 'pending',
            'meta_template_id' => $meta['id'] ?: null,
            'components' => $payload['components'],
        ]);

        return back()->with('success', 'Template submitted to Meta for review. It will appear here once status updates come in or after syncing.');
    }
}

