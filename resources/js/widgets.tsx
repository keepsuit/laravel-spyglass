import { useMutation, useQueryClient } from '@tanstack/react-query';
import { createContext, useContext, useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
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

const deltaTones = { ok: 'text-ok', error: 'text-error' };
const percent = new Intl.NumberFormat(undefined, { style: 'percent', maximumFractionDigits: 1, signDisplay: 'exceptZero' });
const signed = new Intl.NumberFormat(undefined, { signDisplay: 'exceptZero' });

/** Change against the previous period: a percentage, or the difference when there was nothing before. */
export function change(value: unknown, previous?: number, better?: 'up' | 'down'): { text: string; tone: 'ok' | 'error' | null } | null {
    if (typeof value !== 'number' || previous === undefined) return null;

    const diff = value - previous;
    if (diff === 0) return { text: 'No change', tone: null };

    return {
        text: previous === 0 ? signed.format(diff) : percent.format(diff / previous),
        tone: better ? ((diff > 0) === (better === 'up') ? 'ok' : 'error') : null,
    };
}

/** Consecutive metrics share one row. A linked metric opens its page. */
export function Metrics({ metrics, ignored }: { metrics: MetricWidget[]; ignored: (filters: string[]) => string[] }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))]">
            {metrics.map((metric) => {
                const notes = ignored(metric.filters);
                const delta = change(metric.value, metric.previous, metric.better);
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
                        {delta && (
                            <div className="mt-2 text-xs">
                                <div className={`font-medium tabular-nums ${delta.tone ? deltaTones[delta.tone] : ''}`}>{delta.text}</div>
                                <div className="text-muted">vs previous period</div>
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

const height = 200;
const pad = { top: 12, right: 12, bottom: 24, left: 44 };

/**
 * Lines over time, or stacked bars with `style: 'bars'`. Missing points between the first and the last count as
 * zero, at the smallest step between points.
 */
export function Series({ widget, ignored }: { widget: SeriesWidget; ignored: string[] }) {
    const box = useBox();
    const [hover, setHover] = useState<number | null>(null);
    // Drawn at the width it is shown at, so labels keep their size on a phone.
    const frame = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(800);

    useEffect(() => {
        if (!frame.current || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(240, Math.round(entry.contentRect.width))));
        observer.observe(frame.current);
        return () => observer.disconnect();
    }, [widget.points.length > 0]);
    const bars = widget.style === 'bars';
    const points = densify(widget);
    const value = (point: { values: Row }, key: string) => Number(point.values[key]) || 0;
    const peak = (point: { values: Row }) => {
        const values = widget.lines.map((line) => value(point, line.key));
        return bars ? values.reduce((sum, v) => sum + v, 0) : Math.max(0, ...values);
    };
    // Even, so the middle gridline falls on a whole number.
    const max = 2 * Math.ceil(Math.max(1, ...points.map(peak)) / 2);
    const first = points[0]?.time ?? 0;
    const span = Math.max(1, (points.at(-1)?.time ?? 0) - first);
    const plot = width - pad.left - pad.right;
    const slot = plot / Math.max(1, points.length);
    // Bars sit in equal slots, lines on the time axis.
    const x = (index: number) => (bars ? pad.left + (index + 0.5) * slot : pad.left + ((points[index].time - first) / span) * plot);
    const y = (value: number) => height - pad.bottom - (value / max) * (height - pad.top - pad.bottom);
    const active = hover === null ? null : points[hover];
    const colour = (index: number) => {
        const tone = widget.lines[index]?.tone;
        return tone ? `var(--${tone})` : `var(--series-${(index % 8) + 1})`;
    };
    const line = (key: string) => points.map((point, index) => `${x(index)},${y(value(point, key))}`).join(' ');

    const onMove = (event: MouseEvent<SVGSVGElement>) => {
        const box = event.currentTarget.getBoundingClientRect();
        const at = ((event.clientX - box.left) / box.width) * width;
        let nearest = 0;
        points.forEach((_, index) => {
            if (Math.abs(x(index) - at) < Math.abs(x(nearest) - at)) nearest = index;
        });
        setHover(nearest);
    };

    return (
        <section className={box}>
            <Heading label={widget.label} ignored={ignored}>
                <ul className="flex flex-wrap gap-x-4 gap-y-1 text-muted">
                    {widget.lines.map((item, index) => (
                        <li key={item.key} className="flex items-center gap-1.5">
                            <span className={bars ? 'size-2 rounded-sm' : 'h-0.5 w-3 rounded'} style={{ background: colour(index) }} />
                            {item.label}
                        </li>
                    ))}
                </ul>
            </Heading>
            {widget.note && <p className="-mt-2 px-4 pb-2 text-xs text-muted">{widget.note}</p>}
            {points.length === 0 ? (
                <p className="border-t border-line px-4 py-6 text-muted">Nothing recorded in this range.</p>
            ) : (
                <div ref={frame} className="relative mx-3 mb-3">
                    <svg
                        viewBox={`0 0 ${width} ${height}`}
                        className="h-auto w-full"
                        role="img"
                        aria-label={`${widget.label}, ${points.length} points`}
                        onMouseMove={onMove}
                        onMouseLeave={() => setHover(null)}
                    >
                        {[0, 0.5, 1].map((ratio) => (
                            <g key={ratio}>
                                <line x1={pad.left} x2={width - pad.right} y1={y(max * ratio)} y2={y(max * ratio)} stroke="var(--line)" strokeDasharray={ratio ? '2 4' : undefined} />
                                <text x={pad.left - 8} y={y(max * ratio) + 4} textAnchor="end" fontSize="11" fill="var(--muted)">
                                    {formatNumber(max * ratio)}
                                </text>
                            </g>
                        ))}
                        {[0, points.length - 1].map((index, end) => (
                            <text
                                key={end}
                                x={bars ? x(index) + ((end ? 1 : -1) * slot) / 2 : x(index)}
                                y={height - 6}
                                textAnchor={end ? 'end' : 'start'}
                                fontSize="11"
                                fill="var(--muted)"
                            >
                                {formatShort(points[index].time)}
                            </text>
                        ))}
                        {bars && hover !== null && <rect x={x(hover) - slot / 2} y={pad.top} width={slot} height={height - pad.top - pad.bottom} fill="var(--line)" opacity="0.5" />}
                        {bars &&
                            points.flatMap((point, index) => {
                                let base = 0;
                                return widget.lines.map((item, lineIndex) => {
                                    const v = value(point, item.key);
                                    const top = y(base + v);
                                    const bottom = y(base);
                                    base += v;
                                    return v ? (
                                        <rect
                                            key={`${index}-${item.key}`}
                                            x={x(index) - slot * 0.35}
                                            y={top}
                                            width={Math.max(1, slot * 0.7)}
                                            height={bottom - top}
                                            fill={colour(lineIndex)}
                                            rx={Math.min(2, slot * 0.15)}
                                        />
                                    ) : null;
                                });
                            })}
                        {!bars && widget.lines.length > 0 && (
                            <polygon fill={colour(0)} fillOpacity="0.08" points={`${x(0)},${y(0)} ${line(widget.lines[0].key)} ${x(points.length - 1)},${y(0)}`} />
                        )}
                        {!bars &&
                            widget.lines.map((item, index) => (
                                <polyline
                                    key={item.key}
                                    fill="none"
                                    stroke={colour(index)}
                                    strokeWidth="2"
                                    strokeLinejoin="round"
                                    vectorEffect="non-scaling-stroke"
                                    points={line(item.key)}
                                />
                            ))}
                        {!bars && hover !== null && <line x1={x(hover)} x2={x(hover)} y1={pad.top} y2={height - pad.bottom} stroke="var(--muted)" strokeDasharray="3 3" />}
                    </svg>
                    {active && hover !== null && (
                        <div
                            className="pointer-events-none absolute top-0 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg shadow-ink/5"
                            style={x(hover) > width / 2 ? { right: `${(1 - x(hover) / width) * 100 + 2}%` } : { left: `${(x(hover) / width) * 100 + 2}%` }}
                        >
                            <div className="mb-1 font-medium">{formatDate(new Date(active.time).toISOString())}</div>
                            {widget.lines.map((item, index) => (
                                <div key={item.key} className="flex items-center justify-between gap-4">
                                    <span className="flex items-center gap-1.5 text-muted">
                                        <span className={bars ? 'size-2 rounded-sm' : 'size-2 rounded-full'} style={{ background: colour(index) }} />
                                        {item.label}
                                    </span>
                                    <span className="tabular-nums">{formatNumber(value(active, item.key))}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

function densify(widget: SeriesWidget): { time: number; values: Row }[] {
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
