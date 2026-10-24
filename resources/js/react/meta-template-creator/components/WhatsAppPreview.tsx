import { useMemo, useState } from 'react';
import { mappingLabel } from '../lib/template';
import type { TemplateDraft, VariableMap } from '../types';
import {
    cx,
    IconCheck,
    IconDocument,
    IconImage,
    IconVideo,
    IconWhatsApp,
} from './ui';

type PreviewTheme = 'light' | 'dark';

function DoubleCheck({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 18 12" fill="none" className={className} aria-hidden="true">
            <path d="m1 6.5 3 3L11 2" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
            <path d="m7 9 1 1 7-8" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

/** Variables render as a highlighted chip so authors can see dynamic slots. */
function RichText({
    text,
    theme,
    mappings,
    scope,
    className,
}: {
    text: string;
    theme: PreviewTheme;
    mappings: VariableMap;
    scope: 'header' | 'body';
    className?: string;
}) {
    const parts = useMemo(() => text.split(/(\{\{\d+\}\})/g), [text]);

    return (
        <span className={className}>
            {parts.map((part, index) => {
                const match = /^\{\{(\d+)\}\}$/.exec(part);

                if (!match) {
                    return <span key={index}>{part}</span>;
                }

                const number = Number(match[1]);
                const key = `${scope}:${number}`;

                return (
                    <span
                        key={index}
                        title={`Mapped to ${mappingLabel(mappings, key)}`}
                        className={cx(
                            'mx-0.5 inline-flex items-center rounded px-1 py-px font-mono text-[0.92em] font-semibold ring-1 ring-inset',
                            theme === 'dark'
                                ? 'bg-sky-400/20 text-sky-200 ring-sky-300/30'
                                : 'bg-sky-100 text-sky-700 ring-sky-200',
                        )}
                    >
                        {part}
                    </span>
                );
            })}
        </span>
    );
}

function PhoneStatusBar({ theme }: { theme: PreviewTheme }) {
    return (
        <div
            className={cx(
                'flex items-center justify-between px-5 pb-1 pt-2 text-[10px] font-semibold',
                theme === 'dark' ? 'bg-[#1f2c34] text-slate-200' : 'bg-[#075e54] text-white',
            )}
        >
            <span>9:41</span>
            <div className="flex items-center gap-1.5" aria-hidden="true">
                <svg viewBox="0 0 16 11" className="h-2.5 w-3.5 fill-current">
                    <rect x="0" y="6" width="2.5" height="5" rx="0.6" />
                    <rect x="4" y="4" width="2.5" height="7" rx="0.6" />
                    <rect x="8" y="2" width="2.5" height="9" rx="0.6" />
                    <rect x="12" y="0" width="2.5" height="11" rx="0.6" />
                </svg>
                <svg viewBox="0 0 16 12" className="h-2.5 w-3.5 fill-current">
                    <path d="M8 11.2 6 8.8a3 3 0 0 1 4 0zM3.2 5.6 1.6 3.8a9 9 0 0 1 12.8 0l-1.6 1.8a6.6 6.6 0 0 0-9.6 0Z" />
                </svg>
                <svg viewBox="0 0 18 9" className="h-2.5 w-4 fill-current">
                    <rect x="0.5" y="0.5" width="14" height="8" rx="2" />
                    <rect x="15" y="2.5" width="2" height="4" rx="1" />
                </svg>
            </div>
        </div>
    );
}

export function WhatsAppPreview({
    draft,
    mappings,
}: {
    draft: TemplateDraft;
    mappings: VariableMap;
}) {
    const [theme, setTheme] = useState<PreviewTheme>('light');

    const dark = theme === 'dark';
    const headerMediaIcon =
        draft.header.format === 'VIDEO'
            ? IconVideo
            : draft.header.format === 'DOCUMENT'
              ? IconDocument
              : IconImage;

    const MediaIcon = headerMediaIcon;

    const host = useMemo(() => {
        try {
            return new URL(draft.header.mediaUrl).hostname;
        } catch {
            return draft.header.mediaUrl;
        }
    }, [draft.header.mediaUrl]);

    const quickReplies = draft.buttons.quickReplies.filter((text) => text.trim() !== '');
    const ctaButtons = [
        draft.buttons.cta.urlEnabled
            ? { icon: '↗', text: draft.buttons.cta.urlText || 'Visit website' }
            : null,
        draft.buttons.cta.phoneEnabled
            ? { icon: '☎', text: draft.buttons.cta.phoneText || 'Call us' }
            : null,
    ].filter(Boolean) as Array<{ icon: string; text: string }>;

    const bodyText = draft.body.trim() === '' ? 'Your message preview will appear here.' : draft.body;

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h2 className="text-sm font-semibold text-slate-900">Live preview</h2>
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-100">
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
                            Live
                        </span>
                    </div>
                    <p className="mt-0.5 text-[11px] text-slate-500">Exactly what the recipient sees on WhatsApp.</p>
                </div>

                <div
                    className="inline-flex rounded-lg bg-slate-100 p-0.5"
                    role="group"
                    aria-label="Preview theme"
                >
                    {(['light', 'dark'] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={theme === value}
                            onClick={() => setTheme(value)}
                            className={cx(
                                'rounded-md px-2.5 py-1 text-[10px] font-semibold capitalize transition',
                                theme === value
                                    ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200'
                                    : 'text-slate-500 hover:text-slate-700',
                            )}
                        >
                            {value}
                        </button>
                    ))}
                </div>
            </div>

            <div
                className={cx(
                    'mx-auto w-full max-w-[382px] rounded-[2.75rem] p-[9px] shadow-2xl transition-colors',
                    dark ? 'bg-slate-800 shadow-slate-950/30' : 'bg-slate-900 shadow-slate-900/25',
                )}
            >
                <div
                    className={cx(
                        'overflow-hidden rounded-[2.2rem]',
                        dark ? 'bg-[#0b141a]' : 'bg-[#efeae2]',
                    )}
                    aria-label="WhatsApp message preview"
                    role="img"
                >
                    <PhoneStatusBar theme={theme} />

                    {/* Chat header */}
                    <div
                        className={cx(
                            'flex items-center gap-2.5 px-3 py-2.5',
                            dark ? 'bg-[#1f2c34] text-slate-100' : 'bg-[#075e54] text-white',
                        )}
                    >
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#25d366] text-[11px] font-bold text-white ring-2 ring-white/20">
                            SD
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-1">
                                <p className="truncate text-[12px] font-semibold">SocialDesk</p>
                                <span
                                    className={cx(
                                        'inline-flex h-3.5 w-3.5 items-center justify-center rounded-full',
                                        dark ? 'bg-sky-400 text-slate-900' : 'bg-white text-[#075e54]',
                                    )}
                                    title="Verified business"
                                >
                                    <IconCheck className="h-2 w-2" />
                                </span>
                            </div>
                            <p className={cx('text-[9px]', dark ? 'text-slate-400' : 'text-white/70')}>
                                online · business account
                            </p>
                        </div>
                        <div className={cx('flex items-center gap-3', dark ? 'text-slate-400' : 'text-white/80')} aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" className="h-4 w-4">
                                <rect x="3" y="6" width="12" height="12" rx="2" />
                                <path d="m15 10 5-3v10l-5-3" />
                            </svg>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" className="h-4 w-4">
                                <path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2Z" />
                            </svg>
                            <span className="text-lg leading-none">⋮</span>
                        </div>
                    </div>

                    {/* Conversation */}
                    <div
                        className="relative flex min-h-[430px] flex-col justify-end px-3 pb-3 pt-5"
                        style={{
                            backgroundColor: dark ? '#0b141a' : '#efeae2',
                            backgroundImage: dark
                                ? 'radial-gradient(rgba(255,255,255,0.045) 1px, transparent 1px)'
                                : 'radial-gradient(rgba(84,72,50,0.09) 1px, transparent 1px)',
                            backgroundSize: '18px 18px',
                        }}
                    >
                        <div
                            className={cx(
                                'relative ml-auto w-fit max-w-[88%] rounded-2xl rounded-tr-[6px] px-2.5 pb-1.5 pt-2 shadow-sm',
                                dark ? 'bg-[#005c4b] text-slate-100' : 'bg-[#d9fdd3] text-slate-900',
                            )}
                        >
                            <span
                                className={cx(
                                    'absolute -right-[3px] top-0 h-3 w-3 rotate-45',
                                    dark ? 'bg-[#005c4b]' : 'bg-[#d9fdd3]',
                                )}
                                aria-hidden="true"
                            />

                            <div className="relative space-y-1.5">
                                {draft.header.enabled &&
                                    (draft.header.format === 'TEXT' ? (
                                        draft.header.text.trim() !== '' && (
                                            <p
                                                className={cx(
                                                    'pr-1 text-[12.5px] font-semibold leading-snug',
                                                    dark ? 'text-white' : 'text-slate-900',
                                                )}
                                            >
                                                <RichText
                                                    text={draft.header.text}
                                                    theme={theme}
                                                    mappings={mappings}
                                                    scope="header"
                                                />
                                            </p>
                                        )
                                    ) : (
                                        <div
                                            className={cx(
                                                'relative flex h-28 w-full items-center justify-center overflow-hidden rounded-lg',
                                                dark ? 'bg-[#0b141a]/40' : 'bg-black/5',
                                            )}
                                        >
                                            {draft.header.mediaUrl && draft.header.format === 'IMAGE' && (
                                                <img
                                                    src={draft.header.mediaUrl}
                                                    alt="Template header"
                                                    className="absolute inset-0 h-full w-full object-cover"
                                                />
                                            )}
                                            <div
                                                className={cx(
                                                    'relative flex flex-col items-center gap-1 text-[10px]',
                                                    dark ? 'text-slate-400' : 'text-slate-500',
                                                )}
                                            >
                                                <MediaIcon className="h-5 w-5" />
                                                <span className="max-w-[12rem] truncate font-medium">
                                                    {host || `${draft.header.format.toLowerCase()} header`}
                                                </span>
                                            </div>
                                        </div>
                                    ))}

                                <p
                                    className={cx(
                                        'whitespace-pre-wrap break-words text-[12.5px] leading-[1.45]',
                                        draft.body.trim() === '' && 'italic opacity-50',
                                    )}
                                >
                                    {draft.body.trim() === '' ? (
                                        bodyText
                                    ) : (
                                        <RichText
                                            text={draft.body}
                                            theme={theme}
                                            mappings={mappings}
                                            scope="body"
                                        />
                                    )}
                                </p>

                                {draft.footer.enabled && draft.footer.text.trim() !== '' && (
                                    <p
                                        className={cx(
                                            'whitespace-pre-wrap break-words text-[10.5px] leading-snug',
                                            dark ? 'text-slate-400' : 'text-slate-500',
                                        )}
                                    >
                                        {draft.footer.text}
                                    </p>
                                )}

                                <div
                                    className={cx(
                                        'flex items-center justify-end gap-1 pt-0.5 text-[9px]',
                                        dark ? 'text-slate-400' : 'text-slate-500',
                                    )}
                                >
                                    <span>10:42 AM</span>
                                    <DoubleCheck className={cx('h-2.5 w-3.5', dark ? 'text-sky-400' : 'text-sky-500')} />
                                </div>
                            </div>
                        </div>

                        {draft.buttons.enabled && (
                            <div className="ml-auto mt-1 w-fit max-w-[88%] space-y-1">
                                {draft.buttons.mode === 'QUICK_REPLY'
                                    ? quickReplies.map((reply, index) => (
                                          <div
                                              key={index}
                                              className={cx(
                                                  'flex w-full min-w-[10rem] items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-center text-[11px] font-semibold shadow-sm',
                                                  dark
                                                      ? 'bg-[#1f2c34] text-[#53bdeb]'
                                                      : 'bg-white text-[#00a5f4]',
                                              )}
                                          >
                                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-3 w-3">
                                                  <path d="M9 14 4 9l5-5M4 9h9a7 7 0 0 1 7 7v3" />
                                              </svg>
                                              {reply}
                                          </div>
                                      ))
                                    : ctaButtons.map((button, index) => (
                                          <div
                                              key={index}
                                              className={cx(
                                                  'flex w-full min-w-[10rem] items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-center text-[11px] font-semibold shadow-sm',
                                                  dark
                                                      ? 'bg-[#1f2c34] text-[#53bdeb]'
                                                      : 'bg-white text-[#00a5f4]',
                                              )}
                                          >
                                              <span aria-hidden="true">{button.icon}</span>
                                              {button.text}
                                          </div>
                                      ))}
                            </div>
                        )}
                    </div>

                    {/* Composer */}
                    <div
                        className={cx(
                            'flex items-center gap-2 px-2.5 py-2',
                            dark ? 'bg-[#1f2c34]' : 'bg-[#f0f0f0]',
                        )}
                    >
                        <div
                            className={cx(
                                'flex flex-1 items-center gap-2 rounded-full px-3 py-1.5',
                                dark ? 'bg-[#2a3942] text-slate-400' : 'bg-white text-slate-400',
                            )}
                        >
                            <span className="text-sm leading-none">☺</span>
                            <span className="text-[10.5px]">Message</span>
                        </div>
                        <span
                            className={cx(
                                'flex h-8 w-8 items-center justify-center rounded-full text-white',
                                dark ? 'bg-[#00a884]' : 'bg-[#00a884]',
                            )}
                        >
                            <svg viewBox="0 0 24 24" fill="currentColor" className="h-4 w-4">
                                <path d="M3.4 20.6 21 12 3.4 3.4 3.3 10l12 2-12 2z" />
                            </svg>
                        </span>
                    </div>
                </div>
            </div>

            <div className="flex items-center justify-center gap-1.5 text-[10px] text-slate-400">
                <IconWhatsApp className="h-3 w-3 text-[#25d366]" />
                Preview uses sample CRM values. Actual values are filled at send time.
            </div>
        </div>
    );
}
