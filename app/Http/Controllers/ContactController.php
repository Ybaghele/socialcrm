<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET   /w/{workspace}/contacts           the Lead CRM directory
 * GET   /w/{workspace}/contacts/export    streamed CSV of the current filters
 * POST  /w/{workspace}/contacts           create a contact from the "Add Contact" modal
 * PATCH /w/{workspace}/contacts/{contact} stage / assignee change from the inbox dossier
 *
 * The tenant context is set by the "workspace" middleware, so the controllers
 * never type-hint Workspace (see routes/web.php): the BelongsToWorkspace global
 * scope filters every query to the acting workspace.
 */
class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'stage' => (string) $request->query('stage', 'all'),
            'channel' => (string) $request->query('channel', 'all'),
            'consent' => (string) $request->query('consent', 'all'),
        ];

        $contacts = $this->filteredQuery($workspace, $filters)
            ->with(['stage:id,name,color', 'tags:id,name'])
            ->withCount('messages')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Contact $contact) => $this->row($contact));

        return Inertia::render('Contacts', [
            'filters' => $filters,
            'contacts' => $contacts,
            'pipelineStages' => $workspace->pipelineStages()
                ->orderBy('position')
                ->get(['id', 'name']),
            'stats' => [
                'total' => $workspace->contacts()->count(),
                'optedIn' => $workspace->contacts()->where('is_opted_in', true)->count(),
                'newThisMonth' => $workspace->contacts()->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $workspace = $request->attributes->get('workspace');

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'stage' => (string) $request->query('stage', 'all'),
            'channel' => (string) $request->query('channel', 'all'),
            'consent' => (string) $request->query('consent', 'all'),
        ];

        $contacts = $this->filteredQuery($workspace, $filters)
            ->with(['stage:id,name', 'tags:id,name'])
            ->withCount('messages')
            ->get();

        return response()->streamDownload(function () use ($contacts) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Name', 'Phone', 'Email', 'Channels', 'Pipeline stage', 'Tags', 'Total DMs']);

            foreach ($contacts as $contact) {
                fputcsv($out, [
                    $contact->name,
                    $contact->phone,
                    $contact->email,
                    $contact->identities->pluck('channel')->unique()->values()->implode(', '),
                    $contact->stage?->name,
                    $contact->tags->pluck('name')->map(fn ($name) => "#{$name}")->implode(', '),
                    $contact->messages_count,
                ]);
            }

            fclose($out);
        }, 'contacts.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'pipeline_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'is_opted_in' => ['nullable', 'boolean'],
        ]);

        // Tenant context is already established by the "workspace" middleware;
        // the BelongsToWorkspace creating hook stamps workspace_id.
        Contact::create($data);

        return back()->with('success', 'Contact created.');
    }

    public function update(Request $request, string $workspace, Contact $contact): RedirectResponse
    {
        $data = $request->validate([
            'pipeline_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $contact->fill($data)->save();

        return back()->with('success', 'Contact updated.');
    }

    /** Base query with the directory's server-side filters applied. */
    private function filteredQuery(Workspace $workspace, array $filters)
    {
        return $workspace->contacts()
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';

                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhereHas('identities', fn ($q) => $q->where('handle', 'like', $term))
                        ->orWhereHas('tags', fn ($q) => $q->where('name', 'like', $term));
                });
            })
            ->when($filters['channel'] !== 'all' && $filters['channel'] !== '', function ($query) use ($filters) {
                $query->whereHas('identities', fn ($q) => $q->where('channel', $filters['channel']));
            })
            ->when($filters['stage'] !== 'all' && $filters['stage'] !== '', function ($query) use ($filters) {
                $query->where('pipeline_stage_id', (int) $filters['stage']);
            })
            ->when($filters['consent'] === 'opted_in', fn ($query) => $query->where('is_opted_in', true))
            ->when($filters['consent'] === 'opted_out', fn ($query) => $query->where('is_opted_in', false))
            ->with('identities:id,contact_id,channel,handle')
            ->orderByDesc('last_interaction_at')
            ->orderByDesc('id');
    }

    /** Shape one contact row for the CRM table. @return array<string, mixed> */
    private function row(Contact $contact): array
    {
        $identity = $contact->identities->first();

        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'avatarUrl' => $contact->avatar_url,
            'optedIn' => (bool) $contact->is_opted_in,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'handle' => $identity?->handle,
            'channels' => $contact->identities->pluck('channel')->unique()->values()->all(),
            'stage' => $contact->stage ? [
                'name' => $contact->stage->name,
                'color' => $contact->stage->color,
            ] : null,
            'tags' => $contact->tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'messagesCount' => (int) $contact->messages_count,
            'lastTouch' => optional($contact->last_interaction_at)->toIso8601String(),
            'conversationId' => $contact->conversations()->latest('id')->value('id'),
        ];
    }
}
