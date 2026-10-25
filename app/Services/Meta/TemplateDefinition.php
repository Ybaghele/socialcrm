<?php

namespace App\Services\Meta;

use Illuminate\Validation\ValidationException;

/**
 * Turns the builder's flat fields into the component array Meta expects.
 *
 * Only body variables are supported by the sender today, so headers, footers
 * and button labels are deliberately kept static. Keeping this normalisation on
 * the server means the stored mirror and the payload sent to Meta cannot drift.
 */
class TemplateDefinition
{
    /**
     * @param  array{
     *     body: string,
     *     header_text?: ?string,
     *     footer_text?: ?string,
     *     buttons?: array<int, array{type?: string, text?: string, url?: ?string, phone_number?: ?string}>
     * }  $data
     * @return array<int, array<string, mixed>>
     */
    public function components(array $data): array
    {
        $body = trim((string) $data['body']);

        if ($body === '') {
            $this->invalid('body', 'A message body is required.');
        }

        $bodyVariables = $this->bodyVariables($body);
        $this->assertBodyQuality($body, $bodyVariables);

        $components = [];

        $header = trim((string) ($data['header_text'] ?? ''));

        if ($header !== '') {
            $this->rejectVariables('header_text', $header, 'Header text cannot contain variables yet.');
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
        }

        $bodyComponent = ['type' => 'BODY', 'text' => $body];

        if ($bodyVariables > 0) {
            $bodyComponent['example'] = [
                'body_text' => [array_map(fn (int $number) => 'Sample '.$number, range(1, $bodyVariables))],
            ];
        }

        $components[] = $bodyComponent;

        $footer = trim((string) ($data['footer_text'] ?? ''));

        if ($footer !== '') {
            $this->rejectVariables('footer_text', $footer, 'Footer text cannot contain variables.');
            $components[] = ['type' => 'FOOTER', 'text' => $footer];
        }

        $buttons = $this->buttons($data['buttons'] ?? []);

        if ($buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    /**
     * @param  array<int, array{type?: string, text?: string, url?: ?string, phone_number?: ?string}>  $buttons
     * @return array<int, array{type: string, text: string, url?: string, phone_number?: string}>
     */
    private function buttons(array $buttons): array
    {
        $buttons = array_values($buttons);

        if (count($buttons) > 10) {
            $this->invalid('buttons', 'A template can have at most ten buttons.');
        }

        $counts = ['QUICK_REPLY' => 0, 'URL' => 0, 'PHONE_NUMBER' => 0];
        $normalised = [];

        foreach ($buttons as $index => $button) {
            $type = strtoupper(trim((string) ($button['type'] ?? '')));

            if (! array_key_exists($type, $counts)) {
                $this->invalid("buttons.{$index}.type", 'Choose a quick reply, website or phone button.');
            }

            $text = trim((string) ($button['text'] ?? ''));

            if ($text === '') {
                $this->invalid("buttons.{$index}.text", 'Every button needs a label.');
            }

            $this->rejectVariables("buttons.{$index}.text", $text, 'Button labels cannot contain variables.');

            $counts[$type]++;
            $normalisedButton = ['type' => $type, 'text' => $text];

            if ($type === 'URL') {
                $url = trim((string) ($button['url'] ?? ''));

                if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    $this->invalid("buttons.{$index}.url", 'Enter a valid website URL for this button.');
                }

                $this->rejectVariables("buttons.{$index}.url", $url, 'Website buttons cannot contain variables yet.');

                $normalisedButton['url'] = $url;
            }

            if ($type === 'PHONE_NUMBER') {
                $phone = trim((string) ($button['phone_number'] ?? ''));
                $phone = (string) preg_replace('/[\s\-()]+/', '', $phone);

                if (! preg_match('/^\+?[1-9]\d{6,14}$/', $phone)) {
                    $this->invalid("buttons.{$index}.phone_number", 'Enter a phone number with its country code, for example +919820044821.');
                }

                $normalisedButton['phone_number'] = $phone;
            }

            $normalised[] = $normalisedButton;
        }

        if ($counts['URL'] > 2) {
            $this->invalid('buttons', 'A template can have at most two website buttons.');
        }

        if ($counts['PHONE_NUMBER'] > 1) {
            $this->invalid('buttons', 'A template can have at most one phone button.');
        }

        return $this->groupButtons($normalised);
    }

    /**
     * Meta requires quick replies and action buttons to form two contiguous
     * groups. Keep the author's order inside each group.
     *
     * @param  array<int, array{type: string, text: string, url?: string, phone_number?: string}>  $buttons
     * @return array<int, array{type: string, text: string, url?: string, phone_number?: string}>
     */
    private function groupButtons(array $buttons): array
    {
        return [
            ...array_values(array_filter($buttons, fn (array $button) => $button['type'] === 'QUICK_REPLY')),
            ...array_values(array_filter($buttons, fn (array $button) => $button['type'] !== 'QUICK_REPLY')),
        ];
    }

    /** Highest placeholder number in the body; also enforces 1, 2, 3… order. */
    private function bodyVariables(string $body): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);

        if (! $matches[1]) {
            return 0;
        }

        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);

        $highest = max($numbers);

        if ($numbers !== range(1, $highest)) {
            $this->invalid('body', 'Body variables must be sequential, starting at {{1}}.');
        }

        return $highest;
    }

    /**
     * Meta blocks templates whose body starts or ends with a variable, and it
     * rejects bodies without roughly two words of fixed text per variable.
     * Catching both here avoids a failed round trip and a vague Meta rejection.
     */
    private function assertBodyQuality(string $body, int $variableCount): void
    {
        if ($variableCount === 0) {
            return;
        }

        if (preg_match('/^\{\{\d+\}\}/', $body)) {
            $this->invalid('body', 'A template body cannot start with a variable. Add fixed text before {{1}}.');
        }

        if (preg_match('/\{\{\d+\}\}$/', $body)) {
            $this->invalid('body', 'A template body cannot end with a variable. Add fixed text after the last variable.');
        }

        $requiredWords = ($variableCount * 2) + 1;
        $staticWords = $this->staticWordCount($body);

        if ($staticWords < $requiredWords) {
            $missing = $requiredWords - $staticWords;
            $variableLabel = $variableCount === 1 ? 'variable' : 'variables';
            $wordLabel = $missing === 1 ? 'word' : 'words';

            $this->invalid(
                'body',
                "Meta needs at least {$requiredWords} fixed words for {$variableCount} {$variableLabel}. Add {$missing} more {$wordLabel} or remove a variable.",
            );
        }
    }

    private function staticWordCount(string $body): int
    {
        $staticText = (string) preg_replace('/\{\{\d+\}\}/', ' ', $body);
        $words = preg_split('/\s+/u', trim($staticText)) ?: [];

        return count(array_filter(
            $words,
            fn (string $word) => preg_match('/[\p{L}\p{N}]/u', $word) === 1,
        ));
    }

    private function rejectVariables(string $field, string $value, string $message): void
    {
        if (preg_match('/\{\{\d+\}\}/', $value)) {
            $this->invalid($field, $message);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
