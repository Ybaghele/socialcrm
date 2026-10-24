import {
    CRM_FIELDS,
    MAX,
} from './constants';
import type {
    FieldErrors,
    MetaButton,
    MetaComponent,
    MetaTemplatePayload,
    TemplateDraft,
    VariableMap,
} from '../types';

/** Lowercase, spaces to underscores, strip anything Meta rejects. */
export function sanitizeTemplateName(value: string): string {
    return value
        .toLowerCase()
        .replace(/\s+/g, '_')
        .replace(/[^a-z0-9_]/g, '')
        .replace(/_{2,}/g, '_')
        .slice(0, MAX.name);
}

/** Unique variable numbers in the order they first appear. */
export function variablesIn(text: string): number[] {
    const found = new Set<number>();
    for (const match of text.matchAll(/\{\{(\d+)\}\}/g)) {
        const value = Number(match[1]);
        if (Number.isInteger(value) && value > 0) {
            found.add(value);
        }
    }
    return [...found].sort((a, b) => a - b);
}

/** The next token to insert: highest existing number + 1. */
export function nextVariableNumber(text: string): number {
    const numbers = variablesIn(text);
    return numbers.length ? Math.max(...numbers) + 1 : 1;
}

/**
 * Meta requires {{1}}, {{2}}, {{3}} with no gaps. Duplicates of an existing
 * number are harmless (the same value fills both), so we compare unique numbers.
 */
export function sequentialProblem(numbers: number[]): string | null {
    if (numbers.length === 0) {
        return null;
    }

    const expected = Array.from({ length: numbers.length }, (_, index) => index + 1);

    if (numbers.join(',') === expected.join(',')) {
        return null;
    }

    return `Variables must start at {{1}} and increase by one. Found ${numbers
        .map((number) => `{{${number}}}`)
        .join(', ')}.`;
}

export interface VariableKey {
    key: string;
    token: string;
    scope: 'Header' | 'Body';
    number: number;
}

/** Every placeholder that needs a CRM mapping, header first. */
export function variableKeys(draft: TemplateDraft): VariableKey[] {
    const keys: VariableKey[] = [];

    if (draft.header.enabled && draft.header.format === 'TEXT') {
        for (const number of variablesIn(draft.header.text)) {
            keys.push({ key: `header:${number}`, token: `{{${number}}}`, scope: 'Header', number });
        }
    }

    for (const number of variablesIn(draft.body)) {
        keys.push({ key: `body:${number}`, token: `{{${number}}}`, scope: 'Body', number });
    }

    return keys;
}

/** Add mappings for new tokens and drop mappings for removed ones. */
export function ensureMappings(current: VariableMap, keys: string[]): VariableMap {
    const next: VariableMap = {};
    let changed = false;

    for (const key of keys) {
        next[key] = current[key] ?? { field: '', customValue: '' };
        if (!current[key]) {
            changed = true;
        }
    }

    if (Object.keys(current).length !== keys.length) {
        changed = true;
    }

    return changed ? next : current;
}

/** The sample value used both in the preview and in Meta's `example` payload. */
export function exampleFor(mappings: VariableMap, key: string): string {
    const mapping = mappings[key];

    if (!mapping) {
        return 'Sample text';
    }

    if (mapping.field === 'custom') {
        return mapping.customValue.trim() || 'Custom text';
    }

    const crmField = CRM_FIELDS.find((field) => field.value === mapping.field);

    return crmField?.example || 'Sample text';
}

/** Friendly label used in the mapping drawer. */
export function mappingLabel(mappings: VariableMap, key: string): string {
    const mapping = mappings[key];

    if (!mapping) {
        return 'Not mapped';
    }

    if (mapping.field === 'custom') {
        return mapping.customValue.trim() || 'Custom Text';
    }

    return CRM_FIELDS.find((field) => field.value === mapping.field)?.label ?? 'Not mapped';
}

export function defaultDraft(): TemplateDraft {
    return {
        name: '',
        category: 'MARKETING',
        language: 'en_US',
        header: {
            enabled: false,
            format: 'TEXT',
            text: '',
            mediaUrl: '',
        },
        body: '',
        footer: {
            enabled: false,
            text: '',
        },
        buttons: {
            enabled: false,
            mode: 'QUICK_REPLY',
            quickReplies: [''],
            cta: {
                urlEnabled: false,
                urlText: '',
                url: '',
                phoneEnabled: false,
                phoneText: '',
                phoneNumber: '',
            },
        },
    };
}

function isHttpUrl(value: string): boolean {
    const normalized = value.trim().replace(/\{\{\d+\}\}/g, '1');

    try {
        const url = new URL(normalized);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

function validateMappings(draft: TemplateDraft, mappings: VariableMap, errors: FieldErrors): void {
    for (const { key, token } of variableKeys(draft)) {
        const mapping = mappings[key];

        if (!mapping || mapping.field === '') {
            errors[`mapping.${key}`] = `Choose a value for ${token}.`;
        } else if (mapping.field === 'custom' && mapping.customValue.trim() === '') {
            errors[`mapping.${key}`] = `Enter the custom example for ${token}.`;
        }
    }
}

/**
 * Client-side validation mirrors Meta's hard rules. It is intentionally
 * conservative: catching these before the API round trip gives a much better
 * experience than parsing Graph error code 100.
 */
export function validateDraft(draft: TemplateDraft, mappings: VariableMap): FieldErrors {
    const errors: FieldErrors = {};

    if (draft.name.trim() === '') {
        errors.name = 'Template name is required.';
    } else if (!/^[a-z0-9_]+$/.test(draft.name)) {
        errors.name = 'Use lowercase letters, numbers and underscores only.';
    } else if (draft.name.length > MAX.name) {
        errors.name = `Keep the name under ${MAX.name} characters.`;
    }

    if (!draft.category) {
        errors.category = 'Choose a category.';
    }

    if (!draft.language) {
        errors.language = 'Choose a language.';
    }

    if (draft.header.enabled) {
        if (draft.header.format === 'TEXT') {
            const text = draft.header.text.trim();
            const headerVariables = variablesIn(text);

            if (text === '') {
                errors['header.text'] = 'Header text is required when the header is on.';
            } else if (text.length > MAX.headerText) {
                errors['header.text'] = `Keep the header under ${MAX.headerText} characters.`;
            } else if (headerVariables.length > 1) {
                errors['header.text'] = 'The header supports a single {{1}} variable.';
            } else if (headerVariables.length === 1 && headerVariables[0] !== 1) {
                errors['header.text'] = 'The header variable must be {{1}}.';
            }
        } else if (!isHttpUrl(draft.header.mediaUrl)) {
            errors['header.mediaUrl'] = 'Add a media URL (or upload) so Meta can fetch the header asset.';
        }
    }

    const body = draft.body;
    const bodyVariables = variablesIn(body);
    const sequential = sequentialProblem(bodyVariables);

    if (body.trim() === '') {
        errors.body = 'The body cannot be empty.';
    } else if (body.length > MAX.body) {
        errors.body = `Keep the body under ${MAX.body} characters.`;
    } else if (sequential) {
        errors.body = sequential;
    }

    if (draft.footer.enabled) {
        if (draft.footer.text.trim() === '') {
            errors['footer.text'] = 'Footer text is required when the footer is on.';
        } else if (draft.footer.text.length > MAX.footer) {
            errors['footer.text'] = `Keep the footer under ${MAX.footer} characters.`;
        } else if (/\{\{\d+\}\}/.test(draft.footer.text)) {
            errors['footer.text'] = 'Footer text cannot contain variables.';
        }
    }

    if (draft.buttons.enabled) {
        if (draft.buttons.mode === 'QUICK_REPLY') {
            const filled = draft.buttons.quickReplies.filter((text) => text.trim() !== '');

            if (filled.length === 0) {
                errors['buttons.quickReplies'] = 'Add at least one quick reply.';
            } else if (filled.some((text) => text.length > MAX.buttonText)) {
                errors['buttons.quickReplies'] = `Button labels must be ${MAX.buttonText} characters or fewer.`;
            }
        } else {
            const { urlEnabled, urlText, url, phoneEnabled, phoneText, phoneNumber } = draft.buttons.cta;

            if (!urlEnabled && !phoneEnabled) {
                errors['buttons.cta'] = 'Enable a URL button, a phone button, or both.';
            }

            if (urlEnabled) {
                if (urlText.trim() === '') {
                    errors['buttons.urlText'] = 'URL button label is required.';
                } else if (urlText.length > MAX.buttonText) {
                    errors['buttons.urlText'] = `Keep the label under ${MAX.buttonText} characters.`;
                }

                if (!isHttpUrl(url)) {
                    errors['buttons.url'] = 'Enter a valid http(s) URL. A trailing {{1}} is allowed.';
                }
            }

            if (phoneEnabled) {
                if (phoneText.trim() === '') {
                    errors['buttons.phoneText'] = 'Phone button label is required.';
                } else if (phoneText.length > MAX.buttonText) {
                    errors['buttons.phoneText'] = `Keep the label under ${MAX.buttonText} characters.`;
                }

                if (!/^\+?[\d\s()-]{6,20}$/.test(phoneNumber.trim())) {
                    errors['buttons.phoneNumber'] = 'Enter a valid phone number, including country code.';
                }
            }
        }
    }

    validateMappings(draft, mappings, errors);

    return errors;
}

/**
 * Builds the exact body for `POST /{WABA_ID}/message_templates`.
 *
 * Media headers need a `header_handle` from Meta's Resumable Upload API. The
 * draft stores a URL for the demo; a production caller should upload the file
 * first and replace this value with the returned handle.
 */
export function buildPayload(draft: TemplateDraft, mappings: VariableMap): MetaTemplatePayload {
    const components: MetaComponent[] = [];

    if (draft.header.enabled) {
        if (draft.header.format === 'TEXT') {
            const text = draft.header.text.trim();
            const headerVariables = variablesIn(text);

            components.push({
                type: 'HEADER',
                format: 'TEXT',
                text,
                ...(headerVariables.length
                    ? { example: { header_text: [exampleFor(mappings, 'header:1')] } }
                    : {}),
            });
        } else {
            components.push({
                type: 'HEADER',
                format: draft.header.format,
                example: { header_handle: [draft.header.mediaUrl.trim()] },
            });
        }
    }

    const bodyVariables = variablesIn(draft.body);

    components.push({
        type: 'BODY',
        text: draft.body,
        ...(bodyVariables.length
            ? {
                  example: {
                      body_text: [bodyVariables.map((number) => exampleFor(mappings, `body:${number}`))],
                  },
              }
            : {}),
    });

    if (draft.footer.enabled) {
        components.push({ type: 'FOOTER', text: draft.footer.text.trim() });
    }

    if (draft.buttons.enabled) {
        const buttons: MetaButton[] = [];

        if (draft.buttons.mode === 'QUICK_REPLY') {
            for (const text of draft.buttons.quickReplies) {
                if (text.trim() !== '') {
                    buttons.push({ type: 'QUICK_REPLY', text: text.trim() });
                }
            }
        } else {
            const { urlEnabled, urlText, url, phoneEnabled, phoneText, phoneNumber } = draft.buttons.cta;

            if (urlEnabled) {
                buttons.push({ type: 'URL', text: urlText.trim(), url: url.trim() });
            }

            if (phoneEnabled) {
                buttons.push({ type: 'PHONE_NUMBER', text: phoneText.trim(), phone_number: phoneNumber.trim() });
            }
        }

        if (buttons.length > 0) {
            components.push({ type: 'BUTTONS', buttons });
        }
    }

    return {
        name: draft.name,
        category: draft.category,
        language: draft.language,
        allow_category_change: true,
        components,
    };
}
