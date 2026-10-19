<?php

namespace App\Services\Audiences;

use App\Models\Contact;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single place that turns a segment's rule set into a Contact query.
 *
 * The exact same query backs the "N contacts match" preview on the Audiences
 * screen AND the audience a broadcast enrols, so the number the operator sees is
 * always the number that gets messaged.
 *
 * Marketing audiences are opted-in only: every query starts from
 * is_opted_in = true, because opt-outs must never be contacted.
 *
 * Rule shape (segments.filters):
 *   {"all": [{"field": "tag", "op": "in", "value": [1, 2]}, ...]}
 *
 * Rules are ANDed. Unknown fields/operators are ignored rather than fatal so an
 * old saved segment can never break the broadcast pipeline.
 */
final class SegmentQuery
{
    /** The rule fields the builder offers. */
    public const FIELDS = ['tag', 'pipeline_stage', 'source_channel', 'city', 'last_interaction_days'];

    /**
     * @param  array<string, mixed>|null  $filters
     * @return Builder<Contact>
     */
    public static function make(?array $filters): Builder
    {
        return self::apply(Contact::query()->where('is_opted_in', true), $filters);
    }

    /** @return Builder<Contact> */
    public static function for(?Segment $segment): Builder
    {
        return self::make($segment?->filters);
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>|null  $filters
     * @return Builder<Contact>
     */
    public static function apply(Builder $query, ?array $filters): Builder
    {
        $rules = $filters['all'] ?? [];

        if (! is_array($rules)) {
            return $query;
        }

        foreach ($rules as $rule) {
            if (is_array($rule)) {
                self::applyRule($query, $rule);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $rule */
    private static function applyRule(Builder $query, array $rule): void
    {
        $field = (string) ($rule['field'] ?? '');
        $op = (string) ($rule['op'] ?? 'in');
        $value = $rule['value'] ?? null;

        switch ($field) {
            case 'tag':
                $ids = self::ids($value);

                if ($ids === []) {
                    return;
                }

                $op === 'not_in'
                    ? $query->whereDoesntHave('tags', fn ($tag) => $tag->whereIn('tags.id', $ids))
                    : $query->whereHas('tags', fn ($tag) => $tag->whereIn('tags.id', $ids));

                return;

            case 'pipeline_stage':
                $ids = self::ids($value);

                if ($ids === []) {
                    return;
                }

                $op === 'not_in'
                    ? $query->whereNotIn('pipeline_stage_id', $ids)
                    : $query->whereIn('pipeline_stage_id', $ids);

                return;

            case 'source_channel':
                $channels = self::strings($value);

                if ($channels !== []) {
                    $query->whereIn('source_channel', $channels);
                }

                return;

            case 'city':
                $cities = self::strings($value);

                if ($cities !== []) {
                    $query->whereIn('city', $cities);
                }

                return;

            case 'last_interaction_days':
                $days = (int) $value;

                if ($days > 0) {
                    $query->where('last_interaction_at', '>=', now()->subDays($days));
                }

                return;
        }
    }

    /** @return array<int, int> */
    private static function ids(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private static function strings(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
