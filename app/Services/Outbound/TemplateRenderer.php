<?php

namespace App\Services\Outbound;

/**
 * Pure helpers for Meta template components. Body variables only ({{1}}, {{2}}...);
 * header/button variables are not supported yet.
 */
final class TemplateRenderer
{
    public static function bodyText(array $components): string
    {
        foreach ($components as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }

    /** Highest placeholder index in the body ("Hi {{1}}, order {{2}}" => 2). */
    public static function placeholderCount(array $components): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', self::bodyText($components), $m);

        return $m[1] ? max(array_map('intval', $m[1])) : 0;
    }

    /** The text the customer will see; stored on the message for the inbox. */
    public static function render(array $components, array $variables): string
    {
        $vars = array_values($variables);

        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => self::clean($vars[(int) $m[1] - 1] ?? ''), self::bodyText($components));
    }

    /** The "components" array Meta expects when sending. */
    public static function sendComponents(array $components, array $variables): array
    {
        if (self::placeholderCount($components) === 0) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => self::clean($v)], array_values($variables)),
        ]];
    }

    // Meta rejects parameters containing newlines, tabs or long runs of spaces.
    private static function clean(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $value));
    }
}
