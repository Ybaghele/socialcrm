<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\PipelineStage;
use App\Models\Segment;
use App\Models\Tag;
use App\Services\Audiences\SegmentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Saved marketing audiences ("VIP customers", "Mumbai leads", "Re-engage 30d").
 *
 *   GET    /w/{workspace}/segments             the builder + saved audiences
 *   POST   /w/{workspace}/segments             save a new audience
 *   PATCH  /w/{workspace}/segments/{segment}   rename / edit the rules
 *   DELETE /w/{workspace}/segments/{segment}   delete
 *   POST   /w/{workspace}/segments/preview     live "N contacts match" count
 *
 * Segments are tenant-scoped by the BelongsToWorkspace global scope, and the same
 * SegmentQuery resolves both the preview count and the broadcast audience.
 */
class SegmentController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Segments', [
            'segments' => Segment::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Segment $segment) => [
                    'id' => $segment->id,
                    'name' => $segment->name,
                    'filters' => $segment->filters ?? ['all' => []],
                    'count' => $segment->cached_count,
                ])
                ->all(),
            'optedInCount' => Contact::query()->where('is_opted_in', true)->count(),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $segment = Segment::create([
            'name' => $data['name'],
            'filters' => ['all' => $data['rules']],
            'cached_count' => SegmentQuery::make(['all' => $data['rules']])->count(),
        ]);

        return back()->with('success', "Audience “{$segment->name}” saved.");
    }

    public function update(Request $request, string $workspace, Segment $segment): RedirectResponse
    {
        $data = $this->validated($request);

        $segment->update([
            'name' => $data['name'],
            'filters' => ['all' => $data['rules']],
            'cached_count' => SegmentQuery::make(['all' => $data['rules']])->count(),
        ]);

        return back()->with('success', "Audience “{$segment->name}” updated.");
    }

    public function destroy(Request $request, string $workspace, Segment $segment): RedirectResponse
    {
        $name = $segment->name;
        $segment->delete();

        return back()->with('success', "Audience “{$name}” deleted.");
    }

    public function preview(Request $request): JsonResponse
    {
        $rules = $this->validatedRules($request);

        return response()->json([
            'count' => SegmentQuery::make(['all' => $rules])->count(),
        ]);
    }

    /** @return array{name: string, rules: array<int, array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        return ['name' => $data['name'], 'rules' => $this->validatedRules($request)];
    }

    /** @return array<int, array<string, mixed>> */
    private function validatedRules(Request $request): array
    {
        $request->validate([
            'rules' => ['nullable', 'array'],
            'rules.*.field' => ['required', Rule::in(SegmentQuery::FIELDS)],
            'rules.*.op' => ['nullable', Rule::in(['in', 'not_in'])],
        ]);

        return collect($request->input('rules', []))
            ->filter(fn ($rule) => is_array($rule) && in_array($rule['field'] ?? '', SegmentQuery::FIELDS, true))
            ->map(fn (array $rule) => [
                'field' => $rule['field'],
                'op' => $rule['op'] ?? 'in',
                'value' => $rule['value'] ?? [],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'tags' => Tag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'color'])
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color])
                ->all(),
            'stages' => PipelineStage::query()
                ->orderBy('position')
                ->get(['id', 'name', 'color'])
                ->map(fn (PipelineStage $stage) => ['id' => $stage->id, 'name' => $stage->name, 'color' => $stage->color])
                ->all(),
            'channels' => [
                ['value' => 'whatsapp', 'label' => 'WhatsApp'],
                ['value' => 'instagram', 'label' => 'Instagram'],
                ['value' => 'messenger', 'label' => 'Messenger'],
            ],
            'cities' => Contact::query()
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->orderBy('city')
                ->limit(100)
                ->pluck('city')
                ->all(),
        ];
    }
}
