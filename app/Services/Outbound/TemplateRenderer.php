<?php

namespace App\Services\Outbound;

/**
 * Pure helpers for Meta template components. Body variables only ({{1}}, {{2}}...);
 * header/button variables are not supported yet.
 */
final class TemplateRenderer
{
    /** @return array<string, mixed>|null */
    public static function component(array $components, string $type): ?array
    {
        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === $type) {
                return $component;
            }
        }

        return null;
    }

    public static function bodyText(array $components): string
    {
        return (string) (self::component($components, 'BODY')['text'] ?? '');
    }

    public static function headerText(array $components): ?string
    {
        $header = self::component($components, 'HEADER');

        if (! $header || strtoupper((string) ($header['format'] ?? '')) !== 'TEXT') {
            return null;
        }

        return self::textOrNull($header['text'] ?? null);
    }

    public static function footerText(array $components): ?string
    {
        return self::textOrNull(self::component($components, 'FOOTER')['text'] ?? null);
    }

    /**
     * @return array<int, array{type: string, text: string, url?: string, phone_number?: string}>
     */
    public static function buttons(array $components): array
    {
        $buttons = self::component($components, 'BUTTONS')['buttons'] ?? [];

        if (! is_array($buttons)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $button): ?array {
            if (! is_array($button)) {
                return null;
            }

            $text = self::textOrNull($button['text'] ?? null);

            if ($text === null) {
                return null;
            }

            $normalised = [
                'type' => strtoupper((string) ($button['type'] ?? '')),
                'text' => $text,
            ];

            if (isset($button['url']) && is_string($button['url'])) {
                $normalised['url'] = $button['url'];
            }

            if (isset($button['phone_number']) && is_string($button['phone_number'])) {
                $normalised['phone_number'] = $button['phone_number'];
            }

            return $normalised;
        }, $buttons)));
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

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    // Meta rejects parameters containing newlines, tabs or long runs of spaces.
    private static function clean(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $value));
    }
}
