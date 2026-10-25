<?php

namespace App\Http\Controllers;

use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use App\Models\Workspace;
use App\Services\Meta\TemplateCreator;
use App\Services\Meta\TemplateDefinition;
use App\Services\Meta\TemplateDeleter;
use App\Services\Meta\TemplateSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * GET    /w/{workspace}/templates             templates mirrored from Meta
 * POST   /w/{workspace}/templates             submit a new template to Meta
 * POST   /w/{workspace}/templates/sync        pull the latest statuses from Meta
 * PATCH  /w/{workspace}/templates/{template}  pause / resume local broadcasts
 * DELETE /w/{workspace}/templates/{template}  delete it from Meta and this workspace
 *
 * Meta remains the source of truth for approval. The local `is_active` switch
 * only lets an owner pause a template without waiting for Meta to review it.
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
                'active' => $template->isActive(),
                'rejectionReason' => $template->rejection_reason,
                'channelAccount' => $template->channelAccount?->name,
                'channelAccountId' => $template->channel_account_id,
                'header' => $template->headerText(),
                'body' => $template->bodyText(),
                'footer' => $template->footerText(),
                'buttons' => $template->buttons(),
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
                'disabled' => $templates->where('is_active', false)->count(),
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

    public function store(Request $request, TemplateCreator $creator, TemplateDefinition $definition): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->assertManager($request);

        $validated = $request->validate([
            'channel_account_id' => ['required', 'integer', Rule::exists('channel_accounts', 'id')->where(fn ($q) => $q->where('channel', 'whatsapp')->where('status', 'connected'))],
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'category' => ['required', Rule::in(['marketing', 'utility', 'authentication', 'MARKETING', 'UTILITY', 'AUTHENTICATION'])],
            'language' => ['required', 'string', 'max:10'],
            'header_text' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1024'],
            'footer_text' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required', Rule::in(['QUICK_REPLY', 'URL', 'PHONE_NUMBER'])],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'string', 'max:2000'],
            'buttons.*.phone_number' => ['nullable', 'string', 'max:20'],
        ]);

        $components = $definition->components($validated);

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
            'components' => $components,
        ];
        try {
            $meta = $creator->create($account, $payload);

            // Check if Meta rejected it without throwing a hard exception
            if (isset($meta['status']) && $meta['status'] === 'failed') {
                // Meta puts the actionable explanation in error_user_msg (e.g. "Parameters words
                // ratio exceeds limit"); error.message is usually the useless "Invalid parameter".
                $metaError = $meta['error']['error'] ?? $meta['error'] ?? [];

                $errorMessage = $metaError['error_user_msg']
                    ?? $metaError['error_user_title']
                    ?? $metaError['message']
                    ?? 'Unknown validation rejection from Meta API.';

                return back()->with('error', 'Meta rejected this template: '.$errorMessage);
            }

        } catch (ConnectionException $e) {
            return back()->with('error', 'Could not reach Meta: '.$e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
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

    public function update(Request $request, string $workspace, MessageTemplate $template): RedirectResponse
    {
        $this->assertManager($request);

        $request->validate(['is_active' => ['required', 'boolean']]);

        $template->update(['is_active' => $request->boolean('is_active')]);

        return back()->with(
            'success',
            $template->isActive()
                ? "Template “{$template->name}” can be used in broadcasts again."
                : "Template “{$template->name}” is paused for this workspace.",
        );
    }

    public function destroy(Request $request, string $workspace, MessageTemplate $template, TemplateDeleter $deleter): RedirectResponse
    {
        $this->assertManager($request);

        if ($template->campaigns()->whereIn('status', ['active', 'scheduled', 'paused'])->exists()) {
            return back()->with('error', 'Pause or complete the campaigns using this template before deleting it.');
        }

        try {
            $deletedFromMeta = $deleter->delete($template);
        } catch (ConnectionException $e) {
            return back()->with('error', 'Could not reach Meta to delete this template: '.$e->getMessage());
        } catch (Throwable $e) {
            return back()->with('error', 'Could not delete this template: '.$e->getMessage());
        }

        $template->delete();

        return back()->with(
            'success',
            $deletedFromMeta
                ? "Template “{$template->name}” was deleted from Meta and this workspace."
                : "Template “{$template->name}” was removed from this workspace.",
        );
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }

    private function assertManager(Request $request): void
    {
        $role = $request->attributes->get('workspace_role');

        abort_unless(in_array($role, ['owner', 'admin'], true), 403);
    }
}
