<?php

namespace App\Http\Controllers;

use App\Models\CannedResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST   /w/{workspace}/canned-responses                         create a quick reply
 * PATCH  /w/{workspace}/canned-responses/{cannedResponse}       edit one
 * DELETE /w/{workspace}/canned-responses/{cannedResponse}       delete one
 *
 * Quick replies are shared by the whole workspace so every member may manage
 * them. The tenant scope on CannedResponse makes another workspace's id 404.
 */
class CannedResponseController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        CannedResponse::create([
            ...$this->validatePayload($request),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Quick reply created.');
    }

    public function update(Request $request, string $workspace, CannedResponse $cannedResponse): RedirectResponse
    {
        $cannedResponse->update($this->validatePayload($request));

        return back()->with('success', 'Quick reply updated.');
    }

    public function destroy(string $workspace, CannedResponse $cannedResponse): RedirectResponse
    {
        $cannedResponse->delete();

        return back()->with('success', 'Quick reply deleted.');
    }

    /** @return array{title: string, body: string, shortcut: ?string} */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4096'],
            'shortcut' => ['nullable', 'string', 'max:32', 'regex:/^\/?[A-Za-z0-9_-]+$/'],
        ]);

        $shortcut = trim((string) ($data['shortcut'] ?? ''));

        $data['shortcut'] = $shortcut === '' ? null : '/'.ltrim($shortcut, '/');

        return $data;
    }
}
