import type { TemplateCategory, TemplateLanguage } from '../types';

export interface CategoryMeta {
    label: string;
    pricePaise: number;
    blurb: string;
    /** Selected-card accent classes. */
    accent: string;
    dot: string;
}

export const CATEGORIES: Record<TemplateCategory, CategoryMeta> = {
    MARKETING: {
        label: 'Marketing',
        pricePaise: 88,
        blurb: 'Offers, launches and re-engagement.',
        accent: 'border-brand-500 bg-brand-50/70 ring-brand-500/20',
        dot: 'bg-brand-500',
    },
    UTILITY: {
        label: 'Utility',
        pricePaise: 30,
        blurb: 'Order updates, invoices and reminders.',
        accent: 'border-sky-500 bg-sky-50/70 ring-sky-500/20',
        dot: 'bg-sky-500',
    },
    AUTHENTICATION: {
        label: 'Authentication',
        pricePaise: 30,
        blurb: 'One-time passcodes and login codes.',
        accent: 'border-violet-500 bg-violet-50/70 ring-violet-500/20',
        dot: 'bg-violet-500',
    },
};

export const LANGUAGES: Array<{ value: TemplateLanguage; label: string }> = [
    { value: 'en_US', label: 'English (US) - en_US' },
    { value: 'hi_IN', label: 'Hindi - hi_IN' },
];

export const CRM_FIELDS = [
    { value: 'first_name', label: 'Contact First Name', example: 'Aarav' },
    { value: 'last_name', label: 'Contact Last Name', example: 'Sharma' },
    { value: 'custom', label: 'Custom Text', example: '' },
] as const;

export const HEADER_FORMATS = ['IMAGE', 'VIDEO', 'DOCUMENT'] as const;

export const MAX = {
    name: 512,
    headerText: 60,
    body: 1024,
    footer: 60,
    buttonText: 25,
    quickReplies: 3,
} as const;
