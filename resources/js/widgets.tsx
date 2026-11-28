import { useMutation, useQueryClient } from '@tanstack/react-query';
import { areaY, barY, defineChart, lineY } from '@tanstack/charts';
import { Chart } from '@tanstack/charts/react';
import { scaleBand } from '@tanstack/charts/scales/band';
import { scaleLinear } from '@tanstack/charts/scales/linear';
import { scaleOrdinal } from '@tanstack/charts/scales/ordinal';
import { scaleUtc } from '@tanstack/charts/scales/time';
import { tooltip } from '@tanstack/charts/tooltip';
import { createContext, Fragment, useContext, useMemo, useState, type MouseEvent, type ReactNode } from 'react';
import {
    api,
    fill,
    formatDate,
    formatDuration,
    formatNumber,
    formatRelative,
    formatShort,
    href,
    navigate,
    setParams,
    type Action,
    type DetailsWidget,
    type Field,
    type GroupWidget,
    type MetricWidget,
    type Row,
    type SeriesWidget,
    type TableWidget,
    type Tone,
} from './lib';

export function Link({ to, className, children, ...rest }: { to: string; className?: string; title?: string; children: ReactNode; 'aria-current'?: 'page' }) {
    const onClick = (event: MouseEvent) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        navigate(href(to));
    };

    return (
        <a href={href(to)} onClick={onClick} className={className} {...rest}>
            {children}
        </a>
    );
}

const pills: Record<Tone, string> = {
    ok: 'bg-ok/10 text-ok before:bg-ok',
    info: 'bg-info/10 text-info before:bg-info',
    warning: 'bg-warning/12 text-warning before:bg-warning',
    error: 'bg-error/10 text-error before:bg-error',
};

const dots: Record<Tone, string> = { ok: 'bg-ok', info: 'bg-info', warning: 'bg-warning', error: 'bg-error' };

/** A pill, with a dot unless it is a plain `tag`. */
export function Status({ value, tone, tag = false }: { value: string; tone?: Tone; tag?: boolean }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${tag ? '' : 'gap-1.5 before:size-1.5 before:rounded-full'} ${
                tone ? pills[tone] : 'bg-line/50 text-muted before:bg-muted'
            }`}
        >
            {value}
        </span>
    );
}

function Expandable({ summary, children }: { summary: string; children: ReactNode }) {
    return (
        <details className="group max-w-[32ch] open:max-w-[80ch]">
            <summary className="cursor-pointer truncate text-muted group-open:mb-1 hover:text-ink">{summary}</summary>
            {children}
        </details>
    );
}

const pre = 'max-h-96 overflow-auto rounded-lg border border-line bg-bg p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap break-all';

/** One value of a column or a details item. `compact` folds long values, as in table cells. */
export function Value({ field, value, compact = false }: { field: Field; value: unknown; compact?: boolean }) {
    if (value === null || value === undefined || value === '') {
        return <span className="text-muted">–</span>;
    }

    switch (field.type) {
        case 'number':
            return <span className="whitespace-nowrap tabular-nums">{formatNumber(value)}</span>;
        case 'duration':
            return <span className="whitespace-nowrap tabular-nums">{typeof value === 'number' ? formatDuration(value) : String(value)}</span>;
        case 'boolean':
            return value ? <span className="font-medium">Yes</span> : <span className="text-muted">No</span>;
        case 'datetime':
            // The exact time stays on screen: a tooltip does not exist on touch screens.
            return compact ? (
                <time dateTime={String(value)} className="block whitespace-nowrap tabular-nums">
                    {formatRelative(String(value))}
                    <span className="block text-xs text-muted">{formatShort(String(value))}</span>
                </time>
            ) : (
                <time dateTime={String(value)} className="tabular-nums">
                    {formatDate(String(value))}
                    <span className="ml-2 text-muted">{formatRelative(String(value))}</span>
                </time>
            );
        case 'status':
        case 'tag':
            return <Status value={String(value)} tone={field.tones?.[String(value)]} tag={field.type === 'tag'} />;
        case 'runs': {
            const runs = value as { status: string; started_at: string }[];
            const failed = runs.filter((run) => ['error', 'warning'].includes(field.tones?.[run.status] ?? '')).length;
            return (
                <span className="inline-flex items-end gap-0.5" role="img" aria-label={`${runs.length} recent runs, ${failed} not successful`}>
                    {runs.map((run, index) => {
                        const tone = field.tones?.[run.status];
                        return <span key={index} title={`${run.status}, ${formatDate(run.started_at)}`} className={`h-4 w-1.5 rounded-[2px] ${tone ? dots[tone] : 'bg-line'}`} />;
                    })}
                </span>
            );
        }
        case 'link': {
            const link = value as { label: string; url: string | null; title?: string };
            return link.url ? (
                <Link to={link.url} title={link.title} className="link break-words text-accent-ink">
                    {breakable(link.label)}
                </Link>
            ) : (
                <span title={link.title} className="break-words">
                    {breakable(link.label)}
                </span>
            );
        }
        case 'label': {
            const label = value as { text: string; note: string | null; title: string | null };
            return (
                <span title={label.title ?? undefined} className="break-words">
                    {breakable(label.text)}
                    {label.note && <span className="ml-1.5 text-muted">{label.note}</span>}
                </span>
            );
        }
        case 'code':
            return <code className="font-mono text-[0.9em] break-words">{breakable(String(value))}</code>;
        case 'lines':
            return (
                <span className="block whitespace-nowrap">
                    {(value as string[]).map((line, index) => (
                        <span key={index} className="block">
                            {line}
                        </span>
                    ))}
                </span>
            );
        case 'json': {
            const text = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
            const block = <pre className={pre}>{text}</pre>;
            return compact ? <Expandable summary={typeof value === 'string' ? value : JSON.stringify(value)}>{block}</Expandable> : block;
        }
        default: {
            const text = String(value);
            if (compact && (text.length > 80 || text.includes('\n'))) {
                return (
                    <Expandable summary={text.split('\n')[0]}>
                        <pre className={pre}>{text}</pre>
                    </Expandable>
                );
            }
            return compact ? <span className={`block max-w-[48ch] break-words ${text.length > 40 ? 'long' : ''}`}>{breakable(text)}</span> : <span className="break-words whitespace-pre-wrap">{breakable(text)}</span>;
        }
    }
}

/** A chevron pointing right: rotate it to point elsewhere. */
export function Angle({ className = '' }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" className={`size-4 shrink-0 transition-transform duration-150 ${className}`} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="m9 6 6 6-6 6" />
        </svg>
    );
}

/** Class names and paths wrap after a separator rather than inside a name. */
export function breakable(text: string): ReactNode[] {
    return text.split(/(?<=[\\/])/).flatMap((part, index) => (index ? [<wbr key={index} />, part] : [part]));
}

const frame = 'min-w-0 overflow-hidden rounded-[1.25rem] border';

export const card = `${frame} border-line/70 bg-surface`;

// A faint wash of the tone, so a metric that needs attention stands out without shouting.
const tints: Partial<Record<Tone, string>> = {
    error: 'border-[color-mix(in_oklab,var(--surface)_80%,var(--error))] bg-[color-mix(in_oklab,var(--surface)_95%,var(--error))]',
    warning: 'border-[color-mix(in_oklab,var(--surface)_78%,var(--warning))] bg-[color-mix(in_oklab,var(--surface)_94%,var(--warning))]',
};

/** Inside a group a widget has no card of its own, and a lower heading. */
const Nested = createContext(false);

function useBox(): string {
    return useContext(Nested) ? 'border-t border-line' : card;
}

function Heading({ label, ignored, children }: { label: string; ignored: string[]; children?: ReactNode }) {
    const nested = useContext(Nested);
    const Tag = nested ? 'h3' : 'h2';

    return (
        <div className={`flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 pb-3 ${nested ? 'pt-3' : 'pt-4'}`}>
            <Tag className={nested ? 'text-[13px] font-medium text-muted' : 'font-semibold tracking-tight'}>
                {label}
                {ignored.length > 0 && <span className="ml-2 text-xs font-normal text-muted">Not filtered by {ignored.join(', ')}</span>}
            </Tag>
            {children}
        </div>
    );
}

function ViewAll({ to }: { to: string }) {
    return (
        <Link to={to} className="link font-medium text-accent-ink pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:items-center">
            View all
        </Link>
    );
}

/** The widgets of one subject in a single card. */
export function Group({ widget, children }: { widget: GroupWidget; children: ReactNode }) {
    return (
        <section className={`${card} h-full`}>
            <Heading label={widget.label} ignored={[]}>
                {widget.more && <ViewAll to={widget.more} />}
            </Heading>
            <Nested.Provider value={true}>{children}</Nested.Provider>
        </section>
    );
}

/** Consecutive metrics share one row. A linked metric opens its page. */
export function Metrics({ metrics, ignored }: { metrics: MetricWidget[]; ignored: (filters: string[]) => string[] }) {
    return (
        <div className="grid grid-cols-2 gap-3 max-sm:[&>:last-child:nth-child(odd)]:col-span-2 sm:grid-cols-[repeat(auto-fit,minmax(8rem,1fr))]">
            {metrics.map((metric) => {
                const notes = ignored(metric.filters);
                const body = (
                    <>
                        <div className="flex items-start gap-2 text-muted">
                            {metric.tone && <span className={`mt-[0.4375rem] size-2 shrink-0 rounded-full ${dots[metric.tone]}`} />}
                            <span>{metric.label}</span>
                        </div>
                        <div
                            className={`mt-3 text-[1.75rem] leading-none font-semibold tracking-tight tabular-nums ${
                                metric.tone === 'error' ? 'text-error' : metric.tone === 'warning' ? 'text-warning' : ''
                            }`}
                        >
                            {formatMetric(metric.value, metric.format)}
                        </div>
                        {metric.detail && (
                            <div className="mt-2 text-xs text-muted tabular-nums">
                                {metric.detail.label} {formatMetric(metric.detail.value, metric.format)}
                            </div>
                        )}
                        {notes.length > 0 && <div className="mt-2 text-xs text-muted">Not filtered by {notes.join(', ')}</div>}
                    </>
                );

                const box = (metric.tone && tints[metric.tone] && `${frame} ${tints[metric.tone]}`) || card;

                return metric.link ? (
                    <Link key={metric.key} to={metric.link} className={`${box} lift block px-4 py-4`}>
                        {body}
                    </Link>
                ) : (
                    <div key={metric.key} className={`${box} px-4 py-4`}>
                        {body}
                    </div>
                );
            })}
        </div>
    );
}

function formatMetric(value: number | string | null, format?: 'duration'): string {
    return value === null ? '–' : format === 'duration' && typeof value === 'number' ? formatDuration(value) : formatNumber(value);
}

export type Notice = { tone: 'ok' | 'error'; text: string };

/** Action outcomes go to the page: the row that triggered one may be gone after the refresh. */
export const NoticeContext = createContext<(notice: Notice) => void>(() => {});

function Actions({ actions, row = {} }: { actions: Action[]; row?: Row }) {
    const client = useQueryClient();
    const notify = useContext(NoticeContext);
    const mutation = useMutation({
        mutationFn: (action: Action) => api(fill(action.url, row), { method: 'POST' }),
        onSuccess: (_, action) => notify({ tone: 'ok', text: `${action.label}: done.` }),
        onError: (error) => notify({ tone: 'error', text: error.message }),
        onSettled: () => client.invalidateQueries(),
    });

    return (
        <div className="flex items-center justify-end gap-2 whitespace-nowrap">
            {actions.map((action) => (
                <button key={action.key} type="button" disabled={mutation.isPending} onClick={() => mutation.mutate(action)} className="btn">
                    {action.label}
                </button>
            ))}
        </div>
    );
}

/** The comparable part of a cell: the text of a link or label, the first of several lines. */
function sortValue(value: unknown): string | number | null {
    if (value === null || value === undefined || value === '') return null;
    if (Array.isArray(value)) return sortValue(value[0]);
    if (typeof value === 'object') return sortValue((value as { label?: unknown; text?: unknown }).label ?? (value as { text?: unknown }).text);
    return typeof value === 'number' ? value : String(value);
}

/** Rows by a column, empty values last in either direction. */
export function sortRows(rows: Row[], key: string, descending: boolean): Row[] {
    return [...rows].sort((a, b) => {
        const [x, y] = [sortValue(a[key]), sortValue(b[key])];
        if (x === null || y === null) return x === y ? 0 : x === null ? 1 : -1;
        const order = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true });
        return descending ? -order : order;
    });
}

// Counts, durations and times read most usefully from the largest or latest.
const descendingFirst = (field: Field) => ['number', 'duration', 'datetime'].includes(field.type);

export function Table({ widget, ignored, filtered, page, sort = null }: { widget: TableWidget; ignored: string[]; filtered: boolean; page: number | null; sort?: string | null }) {
    const box = useBox();
    const [table, column = ''] = sort?.split(':') ?? [];
    const sorted = table === widget.key ? widget.columns.find((field) => field.sortable && field.key === column.replace(/^-/, '')) : undefined;
    const descending = column.startsWith('-');
    // A paginated table comes sorted from the server.
    const rows = sorted && !widget.pagination ? sortRows(widget.rows, sorted.key, descending) : widget.rows;
    const [opened, setOpened] = useState<Set<number>>(new Set());
    const toggle = (index: number) => setOpened((current) => new Set(current.has(index) ? [...current].filter((item) => item !== index) : [...current, index]));
    const span = widget.columns.length + (widget.actions.length > 0 ? 1 : 0) + (widget.expand ? 1 : 0);
    const sortBy = (field: Field) =>
        setParams({ sort: `${widget.key}:${(sorted === field ? !descending : descendingFirst(field)) ? '-' : ''}${field.key}` });
    // Half a row is narrow: closer columns, the same outer edges.
    const cellX = useContext(Nested) ? 'px-3 first:pl-4 last:pr-4' : 'px-4';
    const { pagination, link } = widget;
    const current = page ?? pagination?.page ?? 1;
    const pages = pagination?.total != null ? Math.max(1, Math.ceil(pagination.total / pagination.per_page)) : null;

    const open = (event: MouseEvent, row: Row, index: number) => {
        if ((!link && !widget.expand) || (event.target as Element).closest('a, button, summary, pre')) return;
        if (window.getSelection()?.toString()) return;
        if (link) navigate(href(fill(link, row)));
        else toggle(index);
    };

    return (
        <section className={box}>
            <Heading label={widget.label} ignored={ignored}>
                <div className="flex items-center gap-4">
                    {pagination?.total != null && <span className="text-muted tabular-nums">{formatNumber(pagination.total)} total</span>}
                    {widget.more && <ViewAll to={widget.more} />}
                </div>
            </Heading>
            {widget.note && <Note text={widget.note} />}
            {rows.length === 0 ? (
                <p className="border-t border-line px-4 py-6 text-muted">{widget.empty ?? (filtered ? 'Nothing matches these filters.' : 'Nothing recorded yet.')}</p>
            ) : (
                <div className="rows overflow-x-auto">
                    <table className="w-full text-left text-[13px]" data-wide={widget.columns.length > 6 || undefined}>
                        <thead className="border-y border-line text-xs text-muted">
                            <tr>
                                {widget.expand && (
                                    <th scope="col" className="w-0 pl-3">
                                        <span className="sr-only">Details</span>
                                    </th>
                                )}
                                {widget.columns.map((column) => (
                                    <th
                                        key={column.key}
                                        scope="col"
                                        aria-sort={sorted === column ? (descending ? 'descending' : 'ascending') : undefined}
                                        className={`${cellX} py-2 font-medium whitespace-nowrap ${alignRight(column) ? 'text-right' : ''}`}
                                    >
                                        {column.sortable ? (
                                            <button type="button" onClick={() => sortBy(column)} className={`inline-flex items-center gap-1 hover:text-ink ${sorted === column ? 'text-ink' : ''}`}>
                                                {column.label}
                                                <span aria-hidden className={sorted === column ? '' : 'opacity-0'}>
                                                    {sorted === column && !descending ? '▲' : '▼'}
                                                </span>
                                            </button>
                                        ) : (
                                            column.label
                                        )}
                                    </th>
                                ))}
                                {widget.actions.length > 0 && (
                                    <th scope="col">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {rows.map((row, index) => (
                                <Fragment key={link ? fill(link, row) : index}>
                                    <tr
                                        onClick={(event) => open(event, row, index)}
                                        className={`align-top transition-colors duration-150 ${link || widget.expand ? 'cursor-pointer hover:bg-bg/70' : ''}`}
                                    >
                                        {widget.expand && (
                                            <td className="w-0 py-2.5 pr-0 pl-3">
                                                <button
                                                    type="button"
                                                    aria-expanded={opened.has(index)}
                                                    aria-label="Details"
                                                    onClick={() => toggle(index)}
                                                    className="flex size-5 items-center justify-center text-muted hover:text-ink"
                                                >
                                                    <Angle className={opened.has(index) ? 'rotate-90' : ''} />
                                                </button>
                                            </td>
                                        )}
                                        {widget.columns.map((column, columnIndex) => (
                                            <td
                                                key={column.key}
                                                // Read only on a phone, where each row is a list of label and value.
                                                data-label={columnIndex > 0 ? column.label : undefined}
                                                data-empty={[null, undefined, ''].includes(row[column.key] as never) || undefined}
                                                className={`${cellX} py-2.5 ${alignRight(column) ? 'text-right' : ''}`}
                                            >
                                                {link && columnIndex === 0 && column.type !== 'link' ? (
                                                    <Link to={fill(link, row)} className="font-medium">
                                                        <Value field={column} value={row[column.key]} compact />
                                                    </Link>
                                                ) : (
                                                    <Value field={column} value={row[column.key]} compact />
                                                )}
                                            </td>
                                        ))}
                                        {widget.actions.length > 0 && (
                                            <td className="px-4 py-2">
                                                <Actions actions={widget.actions} row={row} />
                                            </td>
                                        )}
                                    </tr>
                                    {widget.expand && opened.has(index) && (
                                        <tr className="bg-bg/50">
                                            <td colSpan={span} className="px-4 py-4">
                                                <dl className="grid w-full gap-x-8 gap-y-3 sm:grid-cols-[minmax(6rem,max-content)_minmax(0,1fr)]">
                                                    {widget.expand.map((field) => (
                                                        <div key={field.key} className="contents">
                                                            <dt className="text-muted">{field.label}</dt>
                                                            <dd className="min-w-0 max-sm:mb-2">
                                                                <Value field={field} value={row[field.key]} />
                                                            </dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {pagination && (current > 1 || pagination.has_more) && (
                <nav aria-label={`${widget.label} pages`} className="flex items-center justify-end gap-3 border-t border-line px-4 py-2.5">
                    <span className="text-muted tabular-nums">{pages ? `Page ${current} of ${pages}` : `Page ${current}`}</span>
                    <button type="button" className="btn" disabled={current <= 1} onClick={() => setParams({ page: current > 2 ? String(current - 1) : null })}>
                        Previous
                    </button>
                    <button type="button" className="btn" disabled={!pagination.has_more} onClick={() => setParams({ page: String(current + 1) })}>
                        Next
                    </button>
                </nav>
            )}
        </section>
    );
}

function Note({ text }: { text: string }) {
    return <p className="-mt-2 px-4 pb-2 text-xs text-muted">{text}</p>;
}

const alignRight = (field: Field) => field.type === 'number' || field.type === 'duration';

export function Details({ widget }: { widget: DetailsWidget }) {
    const box = useBox();
    return (
        <section className={box}>
            <Heading label={widget.label} ignored={[]}>
                {widget.actions.length > 0 && <Actions actions={widget.actions} />}
            </Heading>
            {widget.note && <Note text={widget.note} />}
            <dl className="grid gap-x-8 gap-y-3 border-t border-line px-4 py-4 sm:grid-cols-[minmax(9rem,max-content)_1fr]">
                {widget.items.map((item) => (
                    <div key={item.key} className="contents">
                        <dt className="text-muted">{item.label}</dt>
                        <dd className="min-w-0 max-sm:mb-2">
                            <Value field={item} value={item.value} />
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

type Datum = { time: Date; end: Date; series: string; value: number };

const chartHeight = 220;

const minute = 60_000;
const niceSizes = [5, 10, 15, 30, 60, 120, 180, 360, 720, 1440, 2880, 10080].map((minutes) => minutes * minute);

/** The smallest nice multiple of `step` ms that leaves at most `max` of `count` buckets, or the largest one. */
export function bucketSize(count: number, step: number, max: number): number {
    const sizes = niceSizes.filter((size) => size >= step && size % step === 0);
    return sizes.find((size) => Math.ceil((count * step) / size) <= max) ?? sizes.at(-1) ?? step;
}

/**
 * Points summed into buckets of `size` ms, aligned to multiples of it so they stay put between refreshes. A first
 * bucket the range covers only in part is dropped: it would read as a dip.
 */
export function merge(points: Row[], x: string, keys: string[], size: number): { time: number; end: number; values: Record<string, number> }[] {
    const buckets = new Map<number, Record<string, number>>();
    const times = points.map((point) => new Date(String(point[x])).getTime());

    points.forEach((point, index) => {
        if (Number.isNaN(times[index])) return;
        const start = times[index] - (times[index] % size);
        const values = buckets.get(start) ?? Object.fromEntries(keys.map((key) => [key, 0]));
        for (const key of keys) values[key] += Number(point[key]) || 0;
        buckets.set(start, values);
    });

    const merged = [...buckets].sort(([a], [b]) => a - b).map(([time, values]) => ({ time, end: time + size, values }));
    return merged.length > 1 && merged[0].time < Math.min(...times.filter((time) => !Number.isNaN(time))) ? merged.slice(1) : merged;
}

const timeFormat = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });
const dayFormat = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
const day = 86_400_000;

/** The span of a bucket, as the tooltip heading. */
function span(start: Date, end: Date): string {
    const last = new Date(end.getTime() - 1);
    return start.toDateString() === last.toDateString() ? `${formatShort(start.getTime())} – ${timeFormat.format(end)}` : `${formatShort(start.getTime())} – ${formatShort(end.getTime())}`;
}

/**
 * Lines over time, or stacked bars with `style: 'bars'`. With a `step`, neighbouring buckets are summed to fit the
 * width: a few pixels per point for lines, more for bars. The legend shows and hides lines.
 */
export function Series({ widget, ignored }: { widget: SeriesWidget; ignored: string[] }) {
    const box = useBox();
    const [hidden, setHidden] = useState<string[]>([]);
    const bars = widget.style === 'bars';
    const colour = (index: number) => {
        const tone = widget.lines[index]?.tone;
        return tone ? `var(--${tone})` : `var(--series-${(index % 8) + 1})`;
    };
    // Hiding the last visible line shows them all again rather than an empty chart.
    const toggle = (key: string) =>
        setHidden((keys) => (keys.includes(key) ? keys.filter((k) => k !== key) : keys.length + 1 >= widget.lines.length ? [] : [...keys, key]));

    const definition = useMemo(() => {
        const shown = widget.lines.filter((line) => !hidden.includes(line.key));
        const keys = shown.map((line) => line.key);
        // Colours follow the position among all lines, so hiding one leaves the others as they were.
        const color = scaleOrdinal<string, string>()
            .domain(widget.lines.map((line) => line.label))
            .range(widget.lines.map((_, index) => colour(index)));
        const series = new Set(shown.map((line) => line.label));
        const integer = (value: number) => (Number.isInteger(value) ? formatNumber(value) : '');
        // A day per tick over several days, the hour over one: a full date on each would not fit a phone.
        const times = widget.points.map((point) => new Date(String(point[widget.x])).getTime());
        const format = (value: Date) => (Math.max(...times) - Math.min(...times) > 1.5 * day ? dayFormat : timeFormat).format(value);
        const step = (widget.step ?? 0) * 1000;

        // The rows only change with the bucket size, not with every pixel of a resize.
        const cache = new Map<number, Datum[]>();
        const rowsFor = (width: number): Datum[] => {
            const size = step ? bucketSize(widget.points.length, step, Math.max(4, Math.floor((width - 48) / (bars ? 8 : 3)))) : 0;
            if (!cache.has(size)) {
                const points = size
                    ? merge(widget.points, widget.x, keys, size)
                    : widget.points.map((point) => {
                          const time = new Date(String(point[widget.x])).getTime();
                          return { time, end: time, values: Object.fromEntries(keys.map((key) => [key, Number(point[key]) || 0])) };
                      });
                cache.set(
                    size,
                    points.flatMap((point) =>
                        shown.map((line) => ({ time: new Date(point.time), end: new Date(point.end), series: line.label, value: point.values[line.key] ?? 0 })),
                    ),
                );
            }
            return cache.get(size)!;
        };

        const chart = defineChart({
            chart: ({ width }) => {
                const rows = rowsFor(width);
                const first = shown[0] ? rows.filter((row) => row.series === shown[0].label) : [];

                return {
                    marks: bars
                        ? [barY(rows, { x: 'time', y: 'value', z: 'series', inset: 1, radius: { end: 2 } })]
                        : [
                              areaY(first, { x: 'time', y: 'value', fill: first.length ? color(first[0].series) : undefined, fillOpacity: 0.08 }),
                              ...shown.map((line) => lineY(rows.filter((row) => row.series === line.label), { x: 'time', y: 'value', z: 'series', strokeWidth: 2 })),
                          ],
                    scales: {
                        x: bars
                            ? { scale: () => scaleBand<Date>().padding(0.15), axis: { ticks: { format, size: 0 } } }
                            : { scale: scaleUtc, axis: { ticks: { format, size: 0 } } },
                        y: { scale: scaleLinear, nice: true, grid: true, axis: { ticks: { format: integer, size: 0 } } },
                    },
                };
            },
            color: { scale: color },
            theme: { muted: 'var(--muted)', grid: 'var(--line)' },
            focus: 'group-x',
        });

        return defineChart(chart, {
            tooltip: {
                use: tooltip,
                sort: 'color-domain',
                // The shading under the first line is a mark of its own: its points are left out.
                formatGroup: (group) => {
                    const datum = group[0]?.datum;
                    return [
                        datum ? (datum.end > datum.time ? span(datum.time, datum.end) : formatDate(datum.time.toISOString())) : '',
                        ...group.filter((point) => series.has(point.groupLabel)).map((point) => `${point.groupLabel}: ${formatNumber(point.datum.value)}`),
                    ].join('\n');
                },
            },
        });
    }, [widget, hidden, bars]);

    return (
        <section className={box}>
            <Heading label={widget.label} ignored={ignored}>
                <ul className="flex flex-wrap gap-x-1 gap-y-1 text-muted">
                    {widget.lines.map((item, index) => {
                        const swatch = <span className={bars ? 'size-2 rounded-sm' : 'h-0.5 w-3 rounded'} style={{ background: colour(index) }} />;
                        return (
                            <li key={item.key}>
                                {widget.lines.length > 1 ? (
                                    <button
                                        type="button"
                                        aria-pressed={!hidden.includes(item.key)}
                                        title={hidden.includes(item.key) ? `Show ${item.label}` : `Hide ${item.label}`}
                                        onClick={() => toggle(item.key)}
                                        className="flex items-center gap-1.5 rounded-md px-1.5 py-0.5 transition-[opacity,background-color] duration-150 hover:bg-bg aria-[pressed=false]:opacity-40 pointer-coarse:min-h-11"
                                    >
                                        {swatch}
                                        {item.label}
                                    </button>
                                ) : (
                                    <span className="flex items-center gap-1.5 px-1.5 py-0.5">
                                        {swatch}
                                        {item.label}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </Heading>
            {widget.note && <Note text={widget.note} />}
            {widget.points.length === 0 ? (
                <p className="border-t border-line px-4 py-6 text-muted">Nothing recorded in this range.</p>
            ) : (
                <div className="chart mx-3 mb-3 text-[11px] text-ink">
                    <Chart definition={definition} height={chartHeight} ariaLabel={`${widget.label}, ${widget.points.length} points`} />
                </div>
            )}
        </section>
    );
}
