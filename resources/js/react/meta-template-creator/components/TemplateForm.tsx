import { useRef, type Dispatch, type SetStateAction } from 'react';
import {
    CATEGORIES,
    LANGUAGES,
    MAX,
} from '../lib/constants';
import { nextVariableNumber, sanitizeTemplateName, variablesIn } from '../lib/template';
import type { FieldErrors, HeaderFormat, TemplateDraft, TemplateLanguage } from '../types';
import {
    Badge,
    cx,
    FieldError,
    IconCheck,
    IconChevronDown,
    IconDocument,
    IconImage,
    IconPlus,
    IconSparkles,
    IconTrash,
    IconVideo,
    inputClass,
    selectClass,
    SectionCard,
    Toggle,
} from './ui';

interface TemplateFormProps {
    draft: TemplateDraft;
    setDraft: Dispatch<SetStateAction<TemplateDraft>>;
    errors: FieldErrors;
}

const MEDIA_FORMATS: Array<{ value: Exclude<HeaderFormat, 'TEXT'>; label: string; icon: typeof IconImage }> = [
    { value: 'IMAGE', label: 'Image', icon: IconImage },
    { value: 'VIDEO', label: 'Video', icon: IconVideo },
    { value: 'DOCUMENT', label: 'Document', icon: IconDocument },
];

export function TemplateForm({ draft, setDraft, errors }: TemplateFormProps) {
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const headerRef = useRef<HTMLInputElement>(null);

    const bodyVariables = variablesIn(draft.body);
    const headerHasVariable = variablesIn(draft.header.text).includes(1);

    function insertBodyVariable() {
        const element = bodyRef.current;
        const start = element?.selectionStart ?? draft.body.length;
        const end = element?.selectionEnd ?? start;
        const token = `{{${nextVariableNumber(draft.body)}}}`;
        const nextBody = `${draft.body.slice(0, start)}${token}${draft.body.slice(end)}`.slice(0, MAX.body);

        setDraft((previous) => ({ ...previous, body: nextBody }));

        requestAnimationFrame(() => {
            if (!element) {
                return;
            }

            element.focus();
            const position = Math.min(start + token.length, nextBody.length);
            element.setSelectionRange(position, position);
        });
    }

    function insertHeaderVariable() {
        if (headerHasVariable) {
            return;
        }

        const element = headerRef.current;
        const start = element?.selectionStart ?? draft.header.text.length;
        const end = element?.selectionEnd ?? start;
        const token = '{{1}}';
        const nextText = `${draft.header.text.slice(0, start)}${token}${draft.header.text.slice(end)}`.slice(
            0,
            MAX.headerText,
        );

        setDraft((previous) => ({ ...previous, header: { ...previous.header, text: nextText } }));

        requestAnimationFrame(() => {
            if (!element) {
                return;
            }

            element.focus();
            const position = Math.min(start + token.length, nextText.length);
            element.setSelectionRange(position, position);
        });
    }

    function updateCta(patch: Partial<TemplateDraft['buttons']['cta']>) {
        setDraft((previous) => ({
            ...previous,
            buttons: { ...previous.buttons, cta: { ...previous.buttons.cta, ...patch } },
        }));
    }

    return (
        <div className="space-y-4">
            {/* ---------------------------------------------------------- *
             * 01 Identity
             * ---------------------------------------------------------- */}
            <SectionCard
                step="01"
                title="Template identity"
                description="How Meta files this template and which billing category it falls into."
                id="section-identity"
            >
                <div className="space-y-5">
                    <div>
                        <div className="flex items-baseline justify-between gap-3">
                            <label htmlFor="template-name" className="text-xs font-semibold text-slate-800">
                                Template name <span className="text-rose-500">*</span>
                            </label>
                            <span className="text-[10px] tabular-nums text-slate-400">
                                {draft.name.length}/{MAX.name}
                            </span>
                        </div>
                        <input
                            id="template-name"
                            type="text"
                            value={draft.name}
                            onChange={(event) =>
                                setDraft((previous) => ({
                                    ...previous,
                                    name: sanitizeTemplateName(event.target.value),
                                }))
                            }
                            placeholder="diwali_festive_offer_2026"
                            autoComplete="off"
                            spellCheck={false}
                            maxLength={MAX.name}
                            aria-invalid={Boolean(errors.name)}
                            aria-describedby={errors.name ? 'template-name-error' : 'template-name-hint'}
                            className={cx(inputClass, 'mt-1.5 font-mono', errors.name && 'border-rose-300 focus:border-rose-500 focus:ring-rose-500/10')}
                        />
                        <p id="template-name-hint" className="mt-1.5 text-[11px] text-slate-400">
                            Lowercase letters, numbers and underscores only. Spaces become underscores automatically.
                        </p>
                        <FieldError id="template-name-error" message={errors.name} />
                    </div>

                    <fieldset>
                        <legend className="text-xs font-semibold text-slate-800">
                            Category <span className="text-rose-500">*</span>
                        </legend>
                        <div role="radiogroup" aria-label="Template category" className="mt-2 grid gap-2 sm:grid-cols-3">
                            {(Object.keys(CATEGORIES) as Array<keyof typeof CATEGORIES>).map((key) => {
                                const category = CATEGORIES[key];
                                const selected = draft.category === key;

                                return (
                                    <label
                                        key={key}
                                        className={cx(
                                            'group relative cursor-pointer rounded-xl border p-3 transition focus-within:ring-2 focus-within:ring-brand-500/40',
                                            selected
                                                ? `${category.accent} ring-2`
                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="template-category"
                                            value={key}
                                            checked={selected}
                                            onChange={() =>
                                                setDraft((previous) => ({ ...previous, category: key }))
                                            }
                                            className="sr-only"
                                        />
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-2 text-xs font-semibold text-slate-900">
                                                <span className={cx('h-1.5 w-1.5 rounded-full', category.dot)} />
                                                {category.label}
                                            </span>
                                            {selected && <IconCheck className="h-3.5 w-3.5 text-slate-700" />}
                                        </span>
                                        <span className="mt-1 block text-[10px] leading-relaxed text-slate-500">
                                            {category.blurb}
                                        </span>
                                        <span className="mt-2 inline-flex rounded-full bg-white/80 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                                            Costs {category.pricePaise} paise per conversation
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                        <p className="mt-2 text-[11px] text-slate-400">
                            Pricing follows Meta's India rate card and is charged when the conversation opens. Marketing
                            conversations cost ₹0.88; utility and authentication cost ₹0.30.
                        </p>
                        <FieldError id="template-category-error" message={errors.category} />
                    </fieldset>

                    {draft.category === 'AUTHENTICATION' && (
                        <div className="flex items-start gap-2 rounded-xl border border-violet-200 bg-violet-50/60 p-3 text-[11px] leading-relaxed text-violet-800">
                            <IconSparkles className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            <span>
                                Authentication templates are OTP-specific. Meta expects the body to contain the one-time
                                code copy and at least one code button; keep the copy short.
                            </span>
                        </div>
                    )}

                    <div>
                        <label htmlFor="template-language" className="text-xs font-semibold text-slate-800">
                            Language <span className="text-rose-500">*</span>
                        </label>
                        <div className="relative mt-1.5">
                            <select
                                id="template-language"
                                value={draft.language}
                                onChange={(event) =>
                                    setDraft((previous) => ({
                                        ...previous,
                                        language: event.target.value as TemplateLanguage,
                                    }))
                                }
                                className={selectClass}
                            >
                                {LANGUAGES.map((language) => (
                                    <option key={language.value} value={language.value}>
                                        {language.label}
                                    </option>
                                ))}
                            </select>
                            <IconChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        </div>
                        <FieldError id="template-language-error" message={errors.language} />
                    </div>
                </div>
            </SectionCard>

            {/* ---------------------------------------------------------- *
             * 02 Header
             * ---------------------------------------------------------- */}
            <SectionCard
                step="02"
                title="Header"
                description="A short title, or a media attachment. Optional."
                action={
                    <Toggle
                        checked={draft.header.enabled}
                        onChange={(enabled) =>
                            setDraft((previous) => ({ ...previous, header: { ...previous.header, enabled } }))
                        }
                        label="Header"
                        hideLabel
                    />
                }
            >
                {!draft.header.enabled ? (
                    <p className="text-xs text-slate-400">No header. The message will start with the body text.</p>
                ) : (
                    <div className="space-y-4">
                        <div>
                            <p className="text-xs font-semibold text-slate-800">Header type</p>
                            <div className="mt-2 inline-flex rounded-xl bg-slate-100 p-1">
                                {(['TEXT', ...MEDIA_FORMATS.map((format) => format.value)] as HeaderFormat[]).map(
                                    (format) => {
                                        const active = draft.header.format === format;

                                        return (
                                            <button
                                                key={format}
                                                type="button"
                                                aria-pressed={active}
                                                onClick={() =>
                                                    setDraft((previous) => ({
                                                        ...previous,
                                                        header: { ...previous.header, format },
                                                    }))
                                                }
                                                className={cx(
                                                    'rounded-lg px-3 py-1.5 text-[11px] font-semibold capitalize transition',
                                                    active
                                                        ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200'
                                                        : 'text-slate-500 hover:text-slate-700',
                                                )}
                                            >
                                                {format.toLowerCase()}
                                            </button>
                                        );
                                    },
                                )}
                            </div>
                        </div>

                        {draft.header.format === 'TEXT' ? (
                            <div>
                                <div className="flex items-baseline justify-between gap-3">
                                    <label htmlFor="header-text" className="text-xs font-semibold text-slate-800">
                                        Header text
                                    </label>
                                    <span
                                        className={cx(
                                            'text-[10px] tabular-nums',
                                            draft.header.text.length > MAX.headerText * 0.9
                                                ? 'text-amber-600'
                                                : 'text-slate-400',
                                        )}
                                    >
                                        {draft.header.text.length}/{MAX.headerText}
                                    </span>
                                </div>
                                <div className="mt-1.5 flex gap-2">
                                    <input
                                        ref={headerRef}
                                        id="header-text"
                                        type="text"
                                        value={draft.header.text}
                                        onChange={(event) =>
                                            setDraft((previous) => ({
                                                ...previous,
                                                header: { ...previous.header, text: event.target.value.slice(0, MAX.headerText) },
                                            }))
                                        }
                                        placeholder="Your Diwali offer is live, {{1}}"
                                        maxLength={MAX.headerText}
                                        aria-invalid={Boolean(errors['header.text'])}
                                        aria-describedby={errors['header.text'] ? 'header-text-error' : 'header-text-hint'}
                                        className={cx(inputClass, errors['header.text'] && 'border-rose-300')}
                                    />
                                    <button
                                        type="button"
                                        onClick={insertHeaderVariable}
                                        disabled={headerHasVariable}
                                        className="shrink-0 rounded-xl border border-slate-200 bg-white px-3 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                        aria-label="Insert header variable"
                                    >
                                        {'{{1}}'}
                                    </button>
                                </div>
                                <p id="header-text-hint" className="mt-1.5 text-[11px] text-slate-400">
                                    One variable is allowed in the header, and it must be {'{{1}}'}.
                                </p>
                                <FieldError id="header-text-error" message={errors['header.text']} />
                            </div>
                        ) : (
                            <div>
                                <div className="mb-2 inline-flex items-center gap-1.5 text-[11px] font-medium text-slate-500">
                                    {(() => {
                                        const MetaIcon =
                                            MEDIA_FORMATS.find((format) => format.value === draft.header.format)
                                                ?.icon ?? IconImage;
                                        return <MetaIcon className="h-3.5 w-3.5" />;
                                    })()}
                                    {draft.header.format.toLowerCase()} header
                                </div>
                                <label htmlFor="header-media" className="text-xs font-semibold text-slate-800">
                                    Media URL
                                </label>
                                <input
                                    id="header-media"
                                    type="url"
                                    value={draft.header.mediaUrl}
                                    onChange={(event) =>
                                        setDraft((previous) => ({
                                            ...previous,
                                            header: { ...previous.header, mediaUrl: event.target.value },
                                        }))
                                    }
                                    placeholder="https://cdn.example.com/diwali-banner.jpg"
                                    aria-invalid={Boolean(errors['header.mediaUrl'])}
                                    aria-describedby={
                                        errors['header.mediaUrl'] ? 'header-media-error' : 'header-media-hint'
                                    }
                                    className={cx(inputClass, 'mt-1.5', errors['header.mediaUrl'] && 'border-rose-300')}
                                />
                                <p id="header-media-hint" className="mt-1.5 text-[11px] text-slate-400">
                                    SocialDesk uploads this asset to Meta and replaces the URL with the returned
                                    {' '}
                                    <code className="rounded bg-slate-100 px-1 py-0.5 font-mono text-[10px]">
                                        header_handle
                                    </code>
                                    .
                                </p>
                                <FieldError id="header-media-error" message={errors['header.mediaUrl']} />
                            </div>
                        )}
                    </div>
                )}
            </SectionCard>

            {/* ---------------------------------------------------------- *
             * 03 Body
             * ---------------------------------------------------------- */}
            <SectionCard
                step="03"
                title="Body"
                description="The core message. Add sequential variables for personalisation."
            >
                <div className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <label htmlFor="template-body" className="text-xs font-semibold text-slate-800">
                            Message body <span className="text-rose-500">*</span>
                        </label>
                        <button
                            type="button"
                            onClick={insertBodyVariable}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1.5 text-[11px] font-semibold text-brand-700 transition hover:bg-brand-100"
                        >
                            <IconPlus className="h-3 w-3" />
                            Add Variable Token
                        </button>
                    </div>
                    <textarea
                        ref={bodyRef}
                        id="template-body"
                        value={draft.body}
                        onChange={(event) =>
                            setDraft((previous) => ({ ...previous, body: event.target.value.slice(0, MAX.body) }))
                        }
                        rows={7}
                        maxLength={MAX.body}
                        placeholder={'Hi {{1}}, our Diwali sale is live! Enjoy {{2}} off your next order.'}
                        aria-invalid={Boolean(errors.body)}
                        aria-describedby={errors.body ? 'template-body-error' : 'template-body-hint'}
                        className={cx(
                            inputClass,
                            'resize-y leading-relaxed',
                            errors.body && 'border-rose-300 focus:border-rose-500 focus:ring-rose-500/10',
                        )}
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p id="template-body-hint" className="text-[11px] text-slate-400">
                            Variables must be sequential: {'{{1}}'}, {'{{2}}'}, {'{{3}}'} …
                        </p>
                        <span
                            className={cx(
                                'text-[10px] tabular-nums',
                                draft.body.length > MAX.body * 0.9 ? 'text-amber-600' : 'text-slate-400',
                            )}
                        >
                            {draft.body.length}/{MAX.body}
                        </span>
                    </div>
                    {bodyVariables.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                Detected
                            </span>
                            {bodyVariables.map((number) => (
                                <span
                                    key={number}
                                    className="inline-flex items-center rounded-md bg-sky-50 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-200"
                                >
                                    {'{{'}
                                    {number}
                                    {'}}'}
                                </span>
                            ))}
                        </div>
                    )}
                    <FieldError id="template-body-error" message={errors.body} />
                </div>
            </SectionCard>

            {/* ---------------------------------------------------------- *
             * 04 Footer
             * ---------------------------------------------------------- */}
            <SectionCard
                step="04"
                title="Footer"
                description="Optional opt-out or signature. Text only, no variables."
                action={
                    <Toggle
                        checked={draft.footer.enabled}
                        onChange={(enabled) =>
                            setDraft((previous) => ({ ...previous, footer: { ...previous.footer, enabled } }))
                        }
                        label="Footer"
                        hideLabel
                    />
                }
            >
                {!draft.footer.enabled ? (
                    <p className="text-xs text-slate-400">No footer.</p>
                ) : (
                    <div>
                        <div className="flex items-baseline justify-between gap-3">
                            <label htmlFor="footer-text" className="text-xs font-semibold text-slate-800">
                                Footer text
                            </label>
                            <span
                                className={cx(
                                    'text-[10px] tabular-nums',
                                    draft.footer.text.length > MAX.footer * 0.9 ? 'text-amber-600' : 'text-slate-400',
                                )}
                            >
                                {draft.footer.text.length}/{MAX.footer}
                            </span>
                        </div>
                        <input
                            id="footer-text"
                            type="text"
                            value={draft.footer.text}
                            onChange={(event) =>
                                setDraft((previous) => ({
                                    ...previous,
                                    footer: { ...previous.footer, text: event.target.value.slice(0, MAX.footer) },
                                }))
                            }
                            placeholder="Reply STOP to opt out"
                            maxLength={MAX.footer}
                            aria-invalid={Boolean(errors['footer.text'])}
                            aria-describedby={errors['footer.text'] ? 'footer-text-error' : 'footer-text-hint'}
                            className={cx(inputClass, 'mt-1.5', errors['footer.text'] && 'border-rose-300')}
                        />
                        <p id="footer-text-hint" className="mt-1.5 text-[11px] text-slate-400">
                            Variables such as {'{{1}}'} are not allowed in the footer.
                        </p>
                        <FieldError id="footer-text-error" message={errors['footer.text']} />
                    </div>
                )}
            </SectionCard>

            {/* ---------------------------------------------------------- *
             * 05 Buttons
             * ---------------------------------------------------------- */}
            <SectionCard
                step="05"
                title="Buttons"
                description="Give the recipient a one-tap next step. Optional."
                action={
                    <Toggle
                        checked={draft.buttons.enabled}
                        onChange={(enabled) =>
                            setDraft((previous) => ({ ...previous, buttons: { ...previous.buttons, enabled } }))
                        }
                        label="Buttons"
                        hideLabel
                    />
                }
            >
                {!draft.buttons.enabled ? (
                    <p className="text-xs text-slate-400">No buttons.</p>
                ) : (
                    <div className="space-y-4">
                        <div>
                            <label htmlFor="button-mode" className="text-xs font-semibold text-slate-800">
                                Button type
                            </label>
                            <div className="relative mt-1.5">
                                <select
                                    id="button-mode"
                                    value={draft.buttons.mode}
                                    onChange={(event) =>
                                        setDraft((previous) => ({
                                            ...previous,
                                            buttons: {
                                                ...previous.buttons,
                                                mode: event.target.value as TemplateDraft['buttons']['mode'],
                                            },
                                        }))
                                    }
                                    className={selectClass}
                                >
                                    <option value="QUICK_REPLY">Quick Reply - up to 3 plain text buttons</option>
                                    <option value="CTA">Call to Action - URL and/or phone number</option>
                                </select>
                                <IconChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            </div>
                        </div>

                        {draft.buttons.mode === 'QUICK_REPLY' ? (
                            <div>
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-semibold text-slate-800">Quick replies</p>
                                    <Badge tone="slate">
                                        {draft.buttons.quickReplies.length}/{MAX.quickReplies}
                                    </Badge>
                                </div>
                                <div className="mt-2 space-y-2">
                                    {draft.buttons.quickReplies.map((reply, index) => (
                                        <div key={index} className="flex items-center gap-2">
                                            <span className="w-5 shrink-0 text-center text-[10px] font-semibold tabular-nums text-slate-400">
                                                {index + 1}
                                            </span>
                                            <input
                                                type="text"
                                                value={reply}
                                                onChange={(event) =>
                                                    setDraft((previous) => {
                                                        const quickReplies = [...previous.buttons.quickReplies];
                                                        quickReplies[index] = event.target.value.slice(
                                                            0,
                                                            MAX.buttonText,
                                                        );

                                                        return {
                                                            ...previous,
                                                            buttons: { ...previous.buttons, quickReplies },
                                                        };
                                                    })
                                                }
                                                placeholder={index === 0 ? 'Yes, show me' : 'No thanks'}
                                                maxLength={MAX.buttonText}
                                                aria-label={`Quick reply ${index + 1}`}
                                                className={inputClass}
                                            />
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setDraft((previous) => {
                                                        const quickReplies =
                                                            previous.buttons.quickReplies.length === 1
                                                                ? ['']
                                                                : previous.buttons.quickReplies.filter(
                                                                      (_, replyIndex) => replyIndex !== index,
                                                                  );

                                                        return {
                                                            ...previous,
                                                            buttons: { ...previous.buttons, quickReplies },
                                                        };
                                                    })
                                                }
                                                aria-label={`Remove quick reply ${index + 1}`}
                                                className="rounded-lg p-2 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                                            >
                                                <IconTrash className="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                                {draft.buttons.quickReplies.length < MAX.quickReplies && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setDraft((previous) => ({
                                                ...previous,
                                                buttons: {
                                                    ...previous.buttons,
                                                    quickReplies: [...previous.buttons.quickReplies, ''],
                                                },
                                            }))
                                        }
                                        className="mt-2 inline-flex items-center gap-1.5 text-[11px] font-semibold text-brand-700 transition hover:text-brand-800"
                                    >
                                        <IconPlus className="h-3 w-3" /> Add quick reply
                                    </button>
                                )}
                                <FieldError id="buttons-quick-error" message={errors['buttons.quickReplies']} />
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <label
                                        className={cx(
                                            'flex cursor-pointer items-start gap-2 rounded-xl border p-3 transition',
                                            draft.buttons.cta.urlEnabled
                                                ? 'border-brand-300 bg-brand-50/50'
                                                : 'border-slate-200 hover:bg-slate-50',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={draft.buttons.cta.urlEnabled}
                                            onChange={(event) => updateCta({ urlEnabled: event.target.checked })}
                                            className="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                        />
                                        <span>
                                            <span className="block text-xs font-semibold text-slate-900">
                                                Website URL
                                            </span>
                                            <span className="mt-0.5 block text-[10px] text-slate-500">
                                                Send people to a landing page.
                                            </span>
                                        </span>
                                    </label>

                                    <label
                                        className={cx(
                                            'flex cursor-pointer items-start gap-2 rounded-xl border p-3 transition',
                                            draft.buttons.cta.phoneEnabled
                                                ? 'border-brand-300 bg-brand-50/50'
                                                : 'border-slate-200 hover:bg-slate-50',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={draft.buttons.cta.phoneEnabled}
                                            onChange={(event) => updateCta({ phoneEnabled: event.target.checked })}
                                            className="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                        />
                                        <span>
                                            <span className="block text-xs font-semibold text-slate-900">
                                                Phone number
                                            </span>
                                            <span className="mt-0.5 block text-[10px] text-slate-500">
                                                Let people call your team.
                                            </span>
                                        </span>
                                    </label>
                                </div>

                                {draft.buttons.cta.urlEnabled && (
                                    <div className="grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-2">
                                        <div>
                                            <label htmlFor="cta-url-label" className="text-[11px] font-semibold text-slate-700">
                                                Button label
                                            </label>
                                            <input
                                                id="cta-url-label"
                                                type="text"
                                                value={draft.buttons.cta.urlText}
                                                onChange={(event) =>
                                                    updateCta({ urlText: event.target.value.slice(0, MAX.buttonText) })
                                                }
                                                placeholder="Shop now"
                                                maxLength={MAX.buttonText}
                                                aria-invalid={Boolean(errors['buttons.urlText'])}
                                                className={cx(inputClass, 'mt-1')}
                                            />
                                            <FieldError id="cta-url-label-error" message={errors['buttons.urlText']} />
                                        </div>
                                        <div>
                                            <label htmlFor="cta-url" className="text-[11px] font-semibold text-slate-700">
                                                URL
                                            </label>
                                            <input
                                                id="cta-url"
                                                type="url"
                                                value={draft.buttons.cta.url}
                                                onChange={(event) => updateCta({ url: event.target.value })}
                                                placeholder="https://example.com/diwali"
                                                aria-invalid={Boolean(errors['buttons.url'])}
                                                className={cx(inputClass, 'mt-1')}
                                            />
                                            <FieldError id="cta-url-error" message={errors['buttons.url']} />
                                        </div>
                                    </div>
                                )}

                                {draft.buttons.cta.phoneEnabled && (
                                    <div className="grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-2">
                                        <div>
                                            <label htmlFor="cta-phone-label" className="text-[11px] font-semibold text-slate-700">
                                                Button label
                                            </label>
                                            <input
                                                id="cta-phone-label"
                                                type="text"
                                                value={draft.buttons.cta.phoneText}
                                                onChange={(event) =>
                                                    updateCta({ phoneText: event.target.value.slice(0, MAX.buttonText) })
                                                }
                                                placeholder="Call us"
                                                maxLength={MAX.buttonText}
                                                aria-invalid={Boolean(errors['buttons.phoneText'])}
                                                className={cx(inputClass, 'mt-1')}
                                            />
                                            <FieldError id="cta-phone-label-error" message={errors['buttons.phoneText']} />
                                        </div>
                                        <div>
                                            <label htmlFor="cta-phone" className="text-[11px] font-semibold text-slate-700">
                                                Phone number
                                            </label>
                                            <input
                                                id="cta-phone"
                                                type="tel"
                                                value={draft.buttons.cta.phoneNumber}
                                                onChange={(event) => updateCta({ phoneNumber: event.target.value })}
                                                placeholder="+91 98765 43210"
                                                aria-invalid={Boolean(errors['buttons.phoneNumber'])}
                                                className={cx(inputClass, 'mt-1')}
                                            />
                                            <FieldError id="cta-phone-error" message={errors['buttons.phoneNumber']} />
                                        </div>
                                    </div>
                                )}

                                <FieldError id="buttons-cta-error" message={errors['buttons.cta']} />
                            </div>
                        )}
                    </div>
                )}
            </SectionCard>
        </div>
    );
}
