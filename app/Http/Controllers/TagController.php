<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST   /w/{workspace}/tags            create a tag
 * PATCH  /w/{workspace}/tags/{tag}      rename / recolour one
 * DELETE /w/{workspace}/tags/{tag}      delete it everywhere
 *
 * Tags are shared workspace vocabulary, so every member may manage them. The
 * tenant scope on Tag (and the unique index on workspace_id + name) keeps one
 * workspace's tags out of another's.
 */
class TagController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Tag::create($this->validatePayload($request));

        return back()->with('success', 'Tag created.');
    }

    public function update(Request $request, string $workspace, Tag $tag): RedirectResponse
    {
        $tag->update($this->validatePayload($request, $tag));

        return back()->with('success', 'Tag updated.');
    }

    public function destroy(string $workspace, Tag $tag): RedirectResponse
    {
        $tag->delete();

        return back()->with('success', 'Tag deleted.');
    }

    /** @return array{name: string, color: string} */
    private function validatePayload(Request $request, ?Tag $tag = null): array
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        // Store tags without the leading '#' so "#VIP" and "VIP" are the same
        // tag. Leave non-strings untouched so validation rejects arrays.
        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => ltrim(trim($name), '#')]);
        }

        $unique = Rule::unique('tags', 'name')
            ->where(fn ($query) => $query->where('workspace_id', $workspace->getKey()));

        if ($tag) {
            $unique->ignore($tag->getKey());
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', $unique],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $data['color'] = strtolower($data['color'] ?? '#64748b');

        return $data;
    }
}
