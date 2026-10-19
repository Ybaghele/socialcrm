<?php

namespace App\Support;

use App\Models\Contact;

/**
 * Fills {{first_name}}, {{name}}, {{phone}}, {{email}} and {{city}} in a stored
 * message with the contact's real values. Shared by the automation sequence
 * processor; an unknown tag is left untouched rather than blanked, so a mistake
 * is visible instead of silently shipping an empty parameter to Meta.
 */
final class MergeTags
{
    public static function expand(string $value, Contact $contact): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', function (array $match) use ($contact): string {
            return match (strtolower($match[1])) {
                'name', 'full_name' => (string) $contact->name,
                'first_name' => self::firstName($contact),
                'phone', 'mobile' => (string) ($contact->phone ?? ''),
                'email' => (string) ($contact->email ?? ''),
                'city' => (string) ($contact->city ?? ''),
                default => (string) $match[0],
            };
        }, $value);
    }

    /** @param array<int, string> $values */
    public static function expandAll(array $values, Contact $contact): array
    {
        return array_map(fn (string $value) => self::expand($value, $contact), $values);
    }

    public static function firstName(Contact $contact): string
    {
        $name = trim((string) $contact->name);

        return $name === '' ? 'there' : explode(' ', $name)[0];
    }
}
