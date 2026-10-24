import { useEffect, type ReactNode } from 'react';

export function cx(...classes: Array<string | false | null | undefined>): string {
    return classes.filter(Boolean).join(' ');
}

/* ------------------------------------------------------------------ *
 * Inline icons (no icon dependency, so this module drops into any host)
 * ------------------------------------------------------------------ */

type IconProps = { className?: string };

function Svg({ className = 'h-4 w-4', children }: IconProps & { children: ReactNode }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            aria-hidden="true"
        >
            {children}
        </svg>
    );
}

export const IconPlus = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M12 5v14M5 12h14" />
    </Svg>
);

export const IconTrash = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13M10 11v5M14 11v5" />
    </Svg>
);

export const IconCheck = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="m5 13 4 4L19 7" />
    </Svg>
);

export const IconChevronDown = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="m6 9 6 6 6-6" />
    </Svg>
);

export const IconSparkles = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8" />
    </Svg>
);

export const IconImage = ({ className }: IconProps) => (
    <Svg className={className}>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <circle cx="9" cy="10" r="1.5" />
        <path d="m4 18 5-5 4 4 3-3 4 4" />
    </Svg>
);

export const IconVideo = ({ className }: IconProps) => (
    <Svg className={className}>
        <rect x="3" y="6" width="12" height="12" rx="2" />
        <path d="m15 10 5-3v10l-5-3" />
    </Svg>
);

export const IconDocument = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M6 3h8l4 4v14H6zM14 3v5h5" />
        <path d="M9 13h6M9 17h6" />
    </Svg>
);

export const IconSend = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="m4 12 16-8-6 16-2.5-6.5z" />
    </Svg>
);

export const IconShield = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M12 3l7 3v6c0 4.2-2.8 7.4-7 9-4.2-1.6-7-4.8-7-9V6z" />
        <path d="m9 12 2 2 4-4" />
    </Svg>
);

export const IconAlert = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M12 4 2.8 20h18.4zM12 9v5M12 17.5v.5" />
    </Svg>
);

export const IconX = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="m6 6 12 12M18 6 6 18" />
    </Svg>
);

export const IconArrowRight = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M4 12h16M14 6l6 6-6 6" />
    </Svg>
);

export const IconMap = ({ className }: IconProps) => (
    <Svg className={className}>
        <path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2zM9 4v14M15 6v14" />
    </Svg>
);

export const IconWhatsApp = ({ className = 'h-4 w-4' }: IconProps) => (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden="true">
        <path d="M12.04 2a9.9 9.9 0 0 0-8.5 15l-1.3 4.7 4.8-1.3A9.9 9.9 0 1 0 12.04 2Zm0 18.1a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-2.8.8.8-2.8-.2-.3a8.2 8.2 0 1 1 6.7 3.7Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.1-.2 0-.4.1-.5l.6-.7c.1-.2.1-.3 0-.5l-.8-1.9c-.1-.2-.2-.2-.4-.2h-.6c-.2 0-.5.1-.7.3-.7.7-.9 1.6-.7 2.6.3 1.2 1.1 2.3 2.1 3.2 1.4 1.2 3 1.8 4.2 1.9.8.1 1.5-.2 2-.8.2-.2.3-.5.3-.7v-.5c0-.2-.1-.3-.2-.4Z" />
    </svg>
);

/* ------------------------------------------------------------------ *
 * Layout primitives
 * ------------------------------------------------------------------ */

export const inputClass =
    'w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500';

export const selectClass = `${inputClass} appearance-none pr-10`;

export function SectionCard({
    step,
    title,
    description,
    action,
    children,
    id,
}: {
    step: string;
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    id?: string;
}) {
    return (
        <section id={id} className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[11px] font-bold text-brand-700 ring-1 ring-inset ring-brand-100">
                        {step}
                    </span>
                    <div>
                        <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
                        {description && <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{description}</p>}
                    </div>
                </div>
                {action}
            </header>
            <div className="px-5 py-5">{children}</div>
        </section>
    );
}

export function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} role="alert" className="mt-1.5 flex items-start gap-1.5 text-[11px] font-medium text-rose-600">
            <IconAlert className="mt-0.5 h-3 w-3 shrink-0" />
            {message}
        </p>
    );
}

export function Badge({
    children,
    tone = 'slate',
}: {
    children: ReactNode;
    tone?: 'slate' | 'brand' | 'emerald' | 'amber' | 'sky';
}) {
    const tones: Record<string, string> = {
        slate: 'bg-slate-100 text-slate-600 ring-slate-200',
        brand: 'bg-brand-50 text-brand-700 ring-brand-100',
        emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        amber: 'bg-amber-50 text-amber-700 ring-amber-100',
        sky: 'bg-sky-50 text-sky-700 ring-sky-100',
    };

    return (
        <span
            className={cx(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset',
                tones[tone],
            )}
        >
            {children}
        </span>
    );
}

export function Toggle({
    checked,
    onChange,
    label,
    description,
    hideLabel = false,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    description?: string;
    hideLabel?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <div className={hideLabel ? 'sr-only' : undefined}>
                <p className="text-xs font-semibold text-slate-800">{label}</p>
                {description && <p className="mt-0.5 text-[11px] text-slate-500">{description}</p>}
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={`${checked ? 'Disable' : 'Enable'} ${label}`}
                onClick={() => onChange(!checked)}
                className={cx(
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2',
                    checked ? 'bg-brand-600' : 'bg-slate-300',
                )}
            >
                <span
                    className={cx(
                        'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                        checked ? 'translate-x-6' : 'translate-x-1',
                    )}
                />
            </button>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Toast
 * ------------------------------------------------------------------ */

export interface ToastState {
    kind: 'success' | 'error';
    title: string;
    message: string;
}

export function Toast({ toast, onClose }: { toast: ToastState | null; onClose: () => void }) {
    useEffect(() => {
        if (!toast) {
            return;
        }

        const timer = window.setTimeout(onClose, 5000);

        return () => window.clearTimeout(timer);
    }, [toast, onClose]);

    if (!toast) {
        return null;
    }

    const success = toast.kind === 'success';

    return (
        <div
            role="status"
            aria-live="polite"
            className="fixed right-4 top-4 z-50 w-[min(24rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl shadow-slate-900/10"
        >
            <div className="flex items-start gap-3">
                <span
                    className={cx(
                        'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                        success ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600',
                    )}
                >
                    {success ? <IconCheck className="h-4 w-4" /> : <IconAlert className="h-4 w-4" />}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-slate-900">{toast.title}</p>
                    <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{toast.message}</p>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Dismiss notification"
                    className="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                >
                    <IconX className="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    );
}
