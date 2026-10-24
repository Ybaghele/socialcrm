import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { CATEGORIES } from './lib/constants';
import {
    buildPayload,
    defaultDraft,
    ensureMappings,
    validateDraft,
    variableKeys,
} from './lib/template';
import type { FieldErrors, TemplateDraft, VariableMap } from './types';
import { TemplateForm } from './components/TemplateForm';
import { VariableMapping } from './components/VariableMapping';
import { WhatsAppPreview } from './components/WhatsAppPreview';
import {
    Badge,
    IconAlert,
    IconArrowRight,
    IconCheck,
    IconShield,
    IconSparkles,
    IconWhatsApp,
    Toast,
    cx,
    type ToastState,
} from './components/ui';

type Phase = 'idle' | 'submitting' | 'success';

const ERROR_ANCHORS: Record<string, string> = {
    name: 'template-name',
    body: 'template-body',
    'header.text': 'header-text',
    'header.mediaUrl': 'header-media',
    'footer.text': 'footer-text',
};

export function MetaTemplateCreator() {
    const [draft, setDraft] = useState<TemplateDraft>(defaultDraft);
    const [mappings, setMappings] = useState<VariableMap>({});
    const [errors, setErrors] = useState<FieldErrors>({});
    const [toast, setToast] = useState<ToastState | null>(null);
    const [phase, setPhase] = useState<Phase>('idle');
    const [attempted, setAttempted] = useState(false);
    const submitTimer = useRef<number | null>(null);

    const variables = useMemo(() => variableKeys(draft), [draft]);
    const variableSignature = variables.map((variable) => variable.key).join('|');

    // Keep the mapping drawer in lockstep with the tokens in the draft.
    useEffect(() => {
        setMappings((previous) =>
            ensureMappings(
                previous,
                variableSignature === '' ? [] : variableSignature.split('|'),
            ),
        );
    }, [variableSignature]);

    // After the first submit attempt, keep inline errors in sync as the author fixes them.
    useEffect(() => {
        if (attempted) {
            setErrors(validateDraft(draft, mappings));
        }
    }, [attempted, draft, mappings]);

    useEffect(
        () => () => {
            if (submitTimer.current !== null) {
                window.clearTimeout(submitTimer.current);
            }
        },
        [],
    );

    const errorCount = Object.keys(errors).length;
    const showErrorSummary = attempted && errorCount > 0;

    const closeToast = useCallback(() => setToast(null), []);

    function reset() {
        setDraft(defaultDraft());
        setMappings({});
        setErrors({});
        setAttempted(false);
        setPhase('idle');
        setToast(null);
    }

    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (phase === 'submitting') {
            return;
        }

        setAttempted(true);

        const nextErrors = validateDraft(draft, mappings);
        setErrors(nextErrors);

        if (Object.keys(nextErrors).length > 0) {
            setToast({
                kind: 'error',
                title: 'Template is not ready',
                message: `${Object.keys(nextErrors).length} field${
                    Object.keys(nextErrors).length === 1 ? '' : 's'
                } need attention before Meta will accept this template.`,
            });

            const firstKey = Object.keys(nextErrors)[0];
            const anchor = ERROR_ANCHORS[firstKey];

            if (anchor) {
                window.requestAnimationFrame(() => {
                    document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            }

            return;
        }

        const payload = buildPayload(draft, mappings);

        // The real integration replaces this with:
        //   POST /w/{workspace}/templates  ->  Graph /{WABA_ID}/message_templates
        console.log(
            '%c[SocialDesk] POST /{WABA_ID}/message_templates',
            'color:#4f46e5;font-weight:700',
            JSON.stringify(payload, null, 2),
        );

        setPhase('submitting');

        submitTimer.current = window.setTimeout(() => {
            setPhase('success');
            setToast({
                kind: 'success',
                title: 'Submitted to Meta for approval',
                message: 'Review usually completes within minutes, and can take up to 24 hours. We will notify you the moment its status changes.',
            });

            // Mirrors a successful Graph response for the console demo.
            console.log(
                '%c[SocialDesk] Meta accepted the draft',
                'color:#059669;font-weight:700',
                JSON.stringify({ id: 'pending-meta-id', status: 'PENDING', category: payload.category }, null, 2),
            );
        }, 1100);
    }

    const category = CATEGORIES[draft.category];

    return (
        <div className="min-h-screen bg-slate-50">
            <Toast toast={toast} onClose={closeToast} />

            <div className="mx-auto max-w-[1440px] px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                {/* Page header */}
                <div className="flex flex-col gap-4 border-b border-slate-200 pb-6 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-[11px] font-semibold text-slate-400">
                            <span>Templates</span>
                            <span aria-hidden="true">/</span>
                            <span className="text-slate-600">New template</span>
                        </div>
                        <h1 className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900">
                            Create a WhatsApp template
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                            Write it once, preview exactly what the recipient sees, and submit it to Meta for approval
                            without leaving SocialDesk.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-medium text-slate-600 shadow-sm">
                            <IconWhatsApp className="h-3.5 w-3.5 text-[#25d366]" />
                            WhatsApp Cloud API
                        </span>
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-medium text-slate-600 shadow-sm">
                            <IconShield className="h-3.5 w-3.5 text-emerald-500" />
                            {category.pricePaise} paise / conversation
                        </span>
                    </div>
                </div>

                <div className="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_405px]">
                    {/* Editor */}
                    <form onSubmit={handleSubmit} noValidate className="space-y-4" aria-label="Template editor">
                        {showErrorSummary && (
                            <div
                                role="alert"
                                className="rounded-2xl border border-rose-200 bg-rose-50/70 p-4"
                            >
                                <div className="flex items-start gap-2.5">
                                    <span className="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-rose-600 ring-1 ring-inset ring-rose-200">
                                        <IconAlert className="h-3.5 w-3.5" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold text-rose-800">
                                            {errorCount} field{errorCount === 1 ? '' : 's'} need attention
                                        </p>
                                        <ul className="mt-1 list-inside list-disc space-y-0.5 text-[11px] leading-relaxed text-rose-700">
                                            {Object.entries(errors)
                                                .slice(0, 4)
                                                .map(([key, message]) => (
                                                    <li key={key}>{message}</li>
                                                ))}
                                        </ul>
                                        {errorCount > 4 && (
                                            <p className="mt-1 text-[11px] text-rose-600">
                                                +{errorCount - 4} more below.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        <TemplateForm draft={draft} setDraft={setDraft} errors={errors} />

                        <VariableMapping
                            variables={variables}
                            mappings={mappings}
                            errors={errors}
                            onChange={(key, patch) =>
                                setMappings((previous) => ({
                                    ...previous,
                                    [key]: { ...(previous[key] ?? { field: '', customValue: '' }), ...patch },
                                }))
                            }
                        />

                        {/* Submit bar */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div className="flex items-start gap-2.5">
                                    <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100">
                                        <IconSparkles className="h-3.5 w-3.5" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-semibold text-slate-900">
                                            Ready to send for review?
                                        </p>
                                        <p className="mt-0.5 max-w-xl text-[11px] leading-relaxed text-slate-500">
                                            This creates the template in Meta and starts review. Approval usually lands
                                            within minutes and can take up to 24 hours.
                                        </p>
                                    </div>
                                </div>

                                <div className="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={reset}
                                        disabled={phase === 'submitting'}
                                        className="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Reset
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={phase !== 'idle'}
                                        className={cx(
                                            'inline-flex min-w-[13.5rem] items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2',
                                            phase === 'success'
                                                ? 'bg-emerald-600'
                                                : 'bg-brand-600 hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-brand-400',
                                        )}
                                    >
                                        {phase === 'submitting' && (
                                            <>
                                                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                                                Submitting to Meta…
                                            </>
                                        )}
                                        {phase === 'success' && (
                                            <>
                                                <IconCheck className="h-4 w-4" />
                                                Submitted for review
                                            </>
                                        )}
                                        {phase === 'idle' && (
                                            <>
                                                Submit to Meta for Approval
                                                <IconArrowRight className="h-3.5 w-3.5" />
                                            </>
                                        )}
                                    </button>
                                </div>
                            </div>

                            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                                <Badge tone="emerald">
                                    <IconCheck className="h-2.5 w-2.5" /> JSON payload logged to console
                                </Badge>
                                <Badge tone="slate">Category: {category.label}</Badge>
                                <Badge tone="slate">{variables.length} variable{variables.length === 1 ? '' : 's'}</Badge>
                                <span className="ml-auto inline-flex items-center gap-1 text-[10px] text-slate-400">
                                    <IconShield className="h-3 w-3" />
                                    Opens the WhatsApp 24-hour customer service window on send
                                </span>
                            </div>
                        </div>
                    </form>

                    {/* Preview */}
                    <aside className="xl:sticky xl:top-6" aria-label="Live preview">
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                            <WhatsAppPreview draft={draft} mappings={mappings} />
                        </div>

                        <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-amber-50 text-amber-600 ring-1 ring-inset ring-amber-100">
                                    <IconAlert className="h-3 w-3" />
                                </span>
                                <h3 className="text-xs font-semibold text-slate-900">Before Meta reviews</h3>
                            </div>
                            <ul className="mt-2.5 space-y-1.5 text-[11px] leading-relaxed text-slate-500">
                                <li className="flex gap-2">
                                    <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                                    Variables must be strictly sequential. A jump from {'{{1}}'} to {'{{3}}'} is rejected.
                                </li>
                                <li className="flex gap-2">
                                    <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                                    Marketing templates must only go to contacts who opted in.
                                </li>
                                <li className="flex gap-2">
                                    <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                                    Meta may reclassify a template if the category does not match the copy.
                                </li>
                            </ul>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    );
}
