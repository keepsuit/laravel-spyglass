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
        case 'link': {
            const link = value as { label: string; url: string | null };
            return link.url ? (
                <Link to={link.url} className="link break-words text-info">
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

export const card = 'min-w-0 overflow-hidden rounded-xl border border-line bg-surface';

function Heading({ label, ignored, children }: { label: string; ignored: string[]; children?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 pt-4 pb-3">
            <h2 className="font-semibold tracking-tight">
                {label}
                {ignored.length > 0 && <span className="ml-2 text-xs font-normal text-muted">Not filtered by {ignored.join(', ')}</span>}
            </h2>
            {children}
        </div>
    );
}

const dots: Record<Tone, string> = { ok: 'bg-ok', info: 'bg-info', warning: 'bg-warning', error: 'bg-error' };

/** Consecutive metrics share one row. A linked metric opens its page. */
export function Metrics({ metrics, ignored }: { metrics: MetricWidget[]; ignored: (filters: string[]) => string[] }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))]">
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
                            {metric.value === null ? '–' : formatNumber(metric.value)}
                        </div>
                        {notes.length > 0 && <div className="mt-2 text-xs text-muted">Not filtered by {notes.join(', ')}</div>}
                    </>
                );

                return metric.link ? (
                    <Link key={metric.key} to={metric.link} className={`${card} lift block px-4 py-4`}>
                        {body}
                    </Link>
                ) : (
                    <div key={metric.key} className={`${card} px-4 py-4`}>
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
    const { pagination, link } = widget;
    const current = page ?? pagination?.page ?? 1;
    const pages = pagination?.total != null ? Math.max(1, Math.ceil(pagination.total / pagination.per_page)) : null;

    const open = (event: MouseEvent, row: Row) => {
        if (!link || (event.target as Element).closest('a, button, summary, pre')) return;
        if (window.getSelection()?.toString()) return;
        navigate(href(fill(link, row)));
    };

    return (
        <section className={card}>
            <Heading label={widget.label} ignored={ignored}>
                <div className="flex items-center gap-4">
                    {pagination?.total != null && <span className="text-muted tabular-nums">{formatNumber(pagination.total)} total</span>}
                    {widget.more && (
                        <Link to={widget.more} className="link font-medium text-info pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:items-center">
                            View all
                        </Link>
                    )}
                </div>
            </Heading>
            {widget.rows.length === 0 ? (
                <p className="border-t border-line px-4 py-6 text-muted">{widget.empty ?? (filtered ? 'Nothing matches these filters.' : 'Nothing recorded yet.')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-[13px]" data-wide={widget.columns.length > 6 || undefined}>
                        <thead className="border-y border-line bg-bg/60 text-xs text-muted">
                            <tr>
                                {widget.columns.map((column) => (
                                    <th key={column.key} scope="col" className={`px-4 py-2 font-medium whitespace-nowrap ${alignRight(column) ? 'text-right' : ''}`}>
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
                                        <td key={column.key} className={`px-4 py-2.5 ${alignRight(column) ? 'text-right' : ''}`}>
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
    return (
        <section className={card}>
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

/** Lines over time. Missing points between the first and the last count as zero, at the smallest step between points. */
export function Series({ widget, ignored }: { widget: SeriesWidget; ignored: string[] }) {
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
    const points = densify(widget);
    const max = Math.max(1, ...points.flatMap((point) => widget.lines.map((line) => Number(point.values[line.key]) || 0)));
    const first = points[0]?.time ?? 0;
    const span = Math.max(1, (points.at(-1)?.time ?? 0) - first);
    const x = (time: number) => pad.left + ((time - first) / span) * (width - pad.left - pad.right);
    const y = (value: number) => height - pad.bottom - (value / max) * (height - pad.top - pad.bottom);
    const active = hover === null ? null : points[hover];
    const colour = (index: number) => `var(--series-${(index % 8) + 1})`;
    const line = (key: string) => points.map((point) => `${x(point.time)},${y(Number(point.values[key]) || 0)}`).join(' ');

    const onMove = (event: MouseEvent<SVGSVGElement>) => {
        const box = event.currentTarget.getBoundingClientRect();
        const time = first + ((((event.clientX - box.left) / box.width) * width - pad.left) / (width - pad.left - pad.right)) * span;
        let nearest = 0;
        points.forEach((point, index) => {
            if (Math.abs(point.time - time) < Math.abs(points[nearest].time - time)) nearest = index;
        });
        setHover(nearest);
    };

    return (
        <section className={card}>
            <Heading label={widget.label} ignored={ignored}>
                <ul className="flex flex-wrap gap-x-4 gap-y-1 text-muted">
                    {widget.lines.map((item, index) => (
                        <li key={item.key} className="flex items-center gap-1.5">
                            <span className="h-0.5 w-3 rounded" style={{ background: colour(index) }} />
                            {item.label}
                        </li>
                    ))}
                </ul>
            </Heading>
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
                                    {formatNumber(Math.round(max * ratio))}
                                </text>
                            </g>
                        ))}
                        {[points[0], points.at(-1)!].map((point, index) => (
                            <text key={index} x={x(point.time)} y={height - 6} textAnchor={index ? 'end' : 'start'} fontSize="11" fill="var(--muted)">
                                {formatShort(point.time)}
                            </text>
                        ))}
                        {widget.lines.length > 0 && (
                            <polygon
                                fill={colour(0)}
                                fillOpacity="0.08"
                                points={`${x(first)},${y(0)} ${line(widget.lines[0].key)} ${x(points.at(-1)!.time)},${y(0)}`}
                            />
                        )}
                        {widget.lines.map((item, index) => (
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
                        {active && <line x1={x(active.time)} x2={x(active.time)} y1={pad.top} y2={height - pad.bottom} stroke="var(--muted)" strokeDasharray="3 3" />}
                    </svg>
                    {active && (
                        <div
                            className="pointer-events-none absolute top-0 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg shadow-ink/5"
                            style={x(active.time) > width / 2 ? { right: `${(1 - x(active.time) / width) * 100 + 2}%` } : { left: `${(x(active.time) / width) * 100 + 2}%` }}
                        >
                            <div className="mb-1 font-medium">{formatDate(new Date(active.time).toISOString())}</div>
                            {widget.lines.map((item, index) => (
                                <div key={item.key} className="flex items-center justify-between gap-4">
                                    <span className="flex items-center gap-1.5 text-muted">
                                        <span className="size-2 rounded-full" style={{ background: colour(index) }} />
                                        {item.label}
                                    </span>
                                    <span className="tabular-nums">{formatNumber(Number(active.values[item.key]) || 0)}</span>
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
