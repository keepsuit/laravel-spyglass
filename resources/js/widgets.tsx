import { useMutation, useQueryClient } from '@tanstack/react-query';
import { areaY, barY, defineChart, lineY } from '@tanstack/charts';
import { Chart } from '@tanstack/charts/react';
import { scaleBand } from '@tanstack/charts/scales/band';
import { scaleLinear } from '@tanstack/charts/scales/linear';
import { scaleOrdinal } from '@tanstack/charts/scales/ordinal';
import { scaleUtc } from '@tanstack/charts/scales/time';
import { tooltip } from '@tanstack/charts/tooltip';
import { createContext, useContext, useMemo, useState, type MouseEvent, type ReactNode } from 'react';
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

export function Link({ to, className, children }: { to: string; className?: string; children: ReactNode }) {
    const onClick = (event: MouseEvent) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        navigate(href(to));
    };

    return (
        <a href={href(to)} onClick={onClick} className={className}>
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

export function Status({ value, tone }: { value: string; tone?: Tone }) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap before:size-1.5 before:rounded-full ${
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
            return <Status value={String(value)} tone={field.tones?.[String(value)]} />;
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
            const link = value as { label: string; url: string | null };
            return link.url ? (
                <Link to={link.url} className="link break-words text-accent-ink">
                    {breakable(link.label)}
                </Link>
            ) : (
                <span className="break-words">{breakable(link.label)}</span>
            );
        }
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
            return compact ? <span className={`block max-w-[48ch] break-words ${text.length > 40 ? 'long' : ''}`}>{breakable(text)}</span> : <span className="break-words whitespace-pre-wrap">{text}</span>;
        }
    }
}

/** Class names and paths wrap after a separator rather than inside a name. */
function breakable(text: string): ReactNode[] {
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
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))]">
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
                            {metric.value === null ? '–' : metric.format === 'duration' && typeof metric.value === 'number' ? formatDuration(metric.value) : formatNumber(metric.value)}
                        </div>
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

export function Table({ widget, ignored, filtered, page }: { widget: TableWidget; ignored: string[]; filtered: boolean; page: number | null }) {
    const box = useBox();
    // Half a row is narrow: closer columns, the same outer edges.
    const cellX = useContext(Nested) ? 'px-3 first:pl-4 last:pr-4' : 'px-4';
    const { pagination, link } = widget;
    const current = page ?? pagination?.page ?? 1;
    const pages = pagination?.total != null ? Math.max(1, Math.ceil(pagination.total / pagination.per_page)) : null;

    const open = (event: MouseEvent, row: Row) => {
        if (!link || (event.target as Element).closest('a, button, summary, pre')) return;
        if (window.getSelection()?.toString()) return;
        navigate(href(fill(link, row)));
    };

    return (
        <section className={box}>
            <Heading label={widget.label} ignored={ignored}>
                <div className="flex items-center gap-4">
                    {pagination?.total != null && <span className="text-muted tabular-nums">{formatNumber(pagination.total)} total</span>}
                    {widget.more && <ViewAll to={widget.more} />}
                </div>
            </Heading>
            {widget.rows.length === 0 ? (
                <p className="border-t border-line px-4 py-6 text-muted">{widget.empty ?? (filtered ? 'Nothing matches these filters.' : 'Nothing recorded yet.')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-[13px]" data-wide={widget.columns.length > 6 || undefined}>
                        <thead className="border-y border-line text-xs text-muted">
                            <tr>
                                {widget.columns.map((column) => (
                                    <th key={column.key} scope="col" className={`${cellX} py-2 font-medium whitespace-nowrap ${alignRight(column) ? 'text-right' : ''}`}>
                                        {column.label}
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
                            {widget.rows.map((row, index) => (
                                <tr
                                    key={link ? fill(link, row) : index}
                                    onClick={(event) => open(event, row)}
                                    className={`align-top transition-colors duration-150 ${link ? 'cursor-pointer hover:bg-bg/70' : ''}`}
                                >
                                    {widget.columns.map((column, columnIndex) => (
                                        <td key={column.key} className={`${cellX} py-2.5 ${alignRight(column) ? 'text-right' : ''}`}>
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

const alignRight = (field: Field) => field.type === 'number' || field.type === 'duration';

export function Details({ widget }: { widget: DetailsWidget }) {
    const box = useBox();
    return (
        <section className={box}>
            <Heading label={widget.label} ignored={[]}>
                {widget.actions.length > 0 && <Actions actions={widget.actions} />}
            </Heading>
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

type Datum = { time: Date; series: string; value: number };

const chartHeight = 220;

/**
 * Lines over time, or stacked bars with `style: 'bars'`. Missing points between the first and the last count as
 * zero, at the smallest step between points. The legend shows and hides lines.
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
        const points = densify(widget);
        const rows: Datum[] = points.flatMap((point) =>
            shown.map((line) => ({ time: new Date(point.time), series: line.label, value: Number(point.values[line.key]) || 0 })),
        );
        // Colours follow the position among all lines, so hiding one leaves the others as they were.
        const color = scaleOrdinal<string, string>()
            .domain(widget.lines.map((line) => line.label))
            .range(widget.lines.map((_, index) => colour(index)));
        const first = shown[0] ? rows.filter((row) => row.series === shown[0].label) : [];
        const series = new Set(shown.map((line) => line.label));
        const integer = (value: number) => (Number.isInteger(value) ? formatNumber(value) : '');

        return defineChart({
            marks: bars
                ? [barY(rows, { x: 'time', y: 'value', z: 'series', inset: 1, radius: { end: 2 } })]
                : [
                      areaY(first, { x: 'time', y: 'value', fill: first.length ? color(first[0].series) : undefined, fillOpacity: 0.08 }),
                      ...shown.map((line) => lineY(rows.filter((row) => row.series === line.label), { x: 'time', y: 'value', z: 'series', strokeWidth: 2 })),
                  ],
            scales: {
                x: bars
                    ? { scale: () => scaleBand<Date>().padding(0.15), axis: { ticks: { format: (value: Date) => formatShort(value.getTime()), size: 0 } } }
                    : { scale: scaleUtc, axis: { ticks: { format: (value: Date) => formatShort(value.getTime()), size: 0 } } },
                y: { scale: scaleLinear, nice: true, grid: true, axis: { ticks: { format: integer, size: 0 } } },
            },
            color: { scale: color },
            theme: { muted: 'var(--muted)', grid: 'var(--line)' },
            focus: 'group-x',
            tooltip: {
                use: tooltip,
                sort: 'color-domain',
                // The shading under the first line is a mark of its own: its points are left out.
                formatGroup: (group) =>
                    [
                        group[0]?.xValue instanceof Date ? formatDate(group[0].xValue.toISOString()) : '',
                        ...group.filter((point) => series.has(point.groupLabel)).map((point) => `${point.groupLabel}: ${formatNumber(point.datum.value)}`),
                    ].join('\n'),
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
            {widget.note && <p className="-mt-2 px-4 pb-2 text-xs text-muted">{widget.note}</p>}
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

export function densify(widget: SeriesWidget): { time: number; values: Row }[] {
    const points = widget.points
        .map((values) => ({ time: new Date(String(values[widget.x])).getTime(), values }))
        .filter((point) => !Number.isNaN(point.time))
        .sort((a, b) => a.time - b.time);

    let step = Infinity;
    for (let i = 1; i < points.length; i++) step = Math.min(step, points[i].time - points[i - 1].time);
    if (!Number.isFinite(step) || step <= 0) return points;

    const dense: { time: number; values: Row }[] = [];
    for (const point of points) {
        // ponytail: capped gap fill, a server-sent step would make this exact
        while (dense.length && point.time - dense.at(-1)!.time > step && dense.length < 5000) {
            dense.push({ time: dense.at(-1)!.time + step, values: {} });
        }
        dense.push(point);
    }

    return dense;
}
