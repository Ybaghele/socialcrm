import { CRM_FIELDS } from '../lib/constants';
import { exampleFor, mappingLabel, type VariableKey } from '../lib/template';
import type { CrmField, FieldErrors, VariableMap } from '../types';
import { Badge, cx, FieldError, IconChevronDown, IconMap, selectClass, inputClass } from './ui';

interface VariableMappingProps {
    variables: VariableKey[];
    mappings: VariableMap;
    onChange: (key: string, patch: Partial<VariableMap[string]>) => void;
    errors: FieldErrors;
}

export function VariableMapping({ variables, mappings, onChange, errors }: VariableMappingProps) {
    return (
        <section
            aria-labelledby="variable-mapping-heading"
            className="rounded-2xl border border-slate-200 bg-white shadow-sm"
        >
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 ring-1 ring-inset ring-sky-100">
                        <IconMap className="h-3.5 w-3.5" />
                    </span>
                    <div>
                        <h2 id="variable-mapping-heading" className="text-sm font-semibold text-slate-900">
                            Variable mapping
                        </h2>
                        <p className="mt-0.5 text-xs leading-relaxed text-slate-500">
                            Choose where each dynamic value comes from. These example values are sent to Meta for
                            review; real values are resolved per contact at send time.
                        </p>
                    </div>
                </div>
                <Badge tone={variables.length ? 'sky' : 'slate'}>
                    {variables.length} variable{variables.length === 1 ? '' : 's'}
                </Badge>
            </header>

            <div className="px-5 py-5">
                {variables.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-6 py-8 text-center">
                        <IconMap className="h-5 w-5 text-slate-300" />
                        <p className="mt-2 text-xs font-semibold text-slate-600">No variables yet</p>
                        <p className="mt-1 max-w-md text-[11px] leading-relaxed text-slate-400">
                            Add a {'{{1}}'} token to the body (or the header) and a mapping row will appear here
                            automatically.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {variables.map((variable) => {
                            const mapping = mappings[variable.key];
                            const field = mapping?.field ?? '';
                            const selectId = `mapping-${variable.key.replace(':', '-')}`;
                            const customId = `${selectId}-custom`;
                            const errorId = `${selectId}-error`;

                            return (
                                <div
                                    key={variable.key}
                                    className={cx(
                                        'rounded-xl border bg-slate-50/50 p-3 transition',
                                        errors[`mapping.${variable.key}`]
                                            ? 'border-rose-200 bg-rose-50/30'
                                            : 'border-slate-200',
                                    )}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="inline-flex items-center rounded-md bg-sky-50 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-200">
                                            {variable.token}
                                        </span>
                                        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                            {variable.scope}
                                        </span>
                                    </div>

                                    <label htmlFor={selectId} className="mt-2.5 block text-[11px] font-semibold text-slate-700">
                                        Map to CRM field
                                    </label>
                                    <div className="relative mt-1">
                                        <select
                                            id={selectId}
                                            value={field}
                                            onChange={(event) =>
                                                onChange(variable.key, {
                                                    field: event.target.value as CrmField,
                                                    ...(event.target.value === 'custom' ? {} : { customValue: '' }),
                                                })
                                            }
                                            aria-invalid={Boolean(errors[`mapping.${variable.key}`])}
                                            aria-describedby={
                                                errors[`mapping.${variable.key}`] ? errorId : undefined
                                            }
                                            className={cx(selectClass, 'bg-white py-2 text-xs')}
                                        >
                                            <option value="">Select CRM field</option>
                                            {CRM_FIELDS.map((crmField) => (
                                                <option key={crmField.value} value={crmField.value}>
                                                    {crmField.label}
                                                </option>
                                            ))}
                                        </select>
                                        <IconChevronDown className="pointer-events-none absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                                    </div>

                                    {field === 'custom' && (
                                        <div className="mt-2">
                                            <label htmlFor={customId} className="block text-[11px] font-semibold text-slate-700">
                                                Example value
                                            </label>
                                            <input
                                                id={customId}
                                                type="text"
                                                value={mapping?.customValue ?? ''}
                                                onChange={(event) =>
                                                    onChange(variable.key, { customValue: event.target.value })
                                                }
                                                placeholder="e.g. 20%"
                                                className={cx(inputClass, 'mt-1 bg-white py-2 text-xs')}
                                            />
                                        </div>
                                    )}

                                    <p className="mt-2 truncate text-[10px] text-slate-500">
                                        Renders as:{' '}
                                        <span className="font-semibold text-slate-700">
                                            {field === '' ? 'not mapped yet' : exampleFor(mappings, variable.key)}
                                        </span>
                                        {field !== '' && (
                                            <span className="ml-1 text-slate-400">
                                                · {mappingLabel(mappings, variable.key)}
                                            </span>
                                        )}
                                    </p>

                                    <FieldError id={errorId} message={errors[`mapping.${variable.key}`]} />
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </section>
    );
}
