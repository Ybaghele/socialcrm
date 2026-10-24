/** Shared domain types for the Meta WhatsApp Template Creator. */

export type TemplateCategory = 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';

export type TemplateLanguage = 'en_US' | 'hi_IN';

export type HeaderFormat = 'TEXT' | 'IMAGE' | 'VIDEO' | 'DOCUMENT';

export type ButtonMode = 'QUICK_REPLY' | 'CTA';

export type CrmField = '' | 'first_name' | 'last_name' | 'custom';

export interface VariableMapping {
    field: CrmField;
    customValue: string;
}

/**
 * Keyed by `body:1`, `body:2`, `header:1` — so a header variable can never
 * collide with a body variable that happens to use the same number.
 */
export type VariableMap = Record<string, VariableMapping>;

export interface TemplateDraft {
    name: string;
    category: TemplateCategory;
    language: TemplateLanguage;
    header: {
        enabled: boolean;
        format: HeaderFormat;
        text: string;
        mediaUrl: string;
    };
    body: string;
    footer: {
        enabled: boolean;
        text: string;
    };
    buttons: {
        enabled: boolean;
        mode: ButtonMode;
        quickReplies: string[];
        cta: {
            urlEnabled: boolean;
            urlText: string;
            url: string;
            phoneEnabled: boolean;
            phoneText: string;
            phoneNumber: string;
        };
    };
}

export type FieldErrors = Record<string, string>;

export interface MetaTextHeader {
    type: 'HEADER';
    format: 'TEXT';
    text: string;
    example?: { header_text: string[] };
}

export interface MetaMediaHeader {
    type: 'HEADER';
    format: Exclude<HeaderFormat, 'TEXT'>;
    example: { header_handle: string[] };
}

export interface MetaBody {
    type: 'BODY';
    text: string;
    example?: { body_text: string[][] };
}

export interface MetaFooter {
    type: 'FOOTER';
    text: string;
}

export interface MetaButton {
    type: 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER';
    text: string;
    url?: string;
    phone_number?: string;
}

export interface MetaButtons {
    type: 'BUTTONS';
    buttons: MetaButton[];
}

export type MetaComponent = MetaTextHeader | MetaMediaHeader | MetaBody | MetaFooter | MetaButtons;

/** The body sent to `POST /{WABA_ID}/message_templates`. */
export interface MetaTemplatePayload {
    name: string;
    category: TemplateCategory;
    language: TemplateLanguage;
    allow_category_change: true;
    components: MetaComponent[];
}
