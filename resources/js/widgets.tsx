import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type MouseEvent, type ReactNode } from 'react';
import {
    api,
    fill,
    formatDate,
    formatDuration,
    formatNumber,
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

const toneClasses: Record<Tone, string> = {
    ok: 'text-ok before:bg-ok',
    info: 'text-info before:bg-info',
    warning: 'text-warning before:bg-warning',
    error: 'text-error before:bg-error',
};

export function Status({ value, tone }: { value: string; tone?: Tone }) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 font-medium whitespace-nowrap before:size-2 before:rounded-full ${
                tone ? toneClasses[tone] : 'text-muted before:bg-muted'
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

const pre = 'max-h-96 overflow-auto rounded border border-line bg-bg p-2 font-mono text-xs whitespace-pre-wrap break-all';

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
            return (
                <time dateTime={String(value)} title={String(value)} className="whitespace-nowrap tabular-nums">
                    {formatDate(String(value))}
                </time>
            );
        case 'status':
            return <Status value={String(value)} tone={field.tones?.[String(value)]} />;
        case 'link': {
            const link = value as { label: string; url: string | null };
            return link.url ? (
                <Link to={link.url} className="text-info underline-offset-2 hover:underline">
                    {link.label}
                </Link>
            ) : (
                <span>{link.label}</span>
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
            return <span className={compact ? 'block max-w-[48ch] min-w-20 [overflow-wrap:anywhere]' : 'break-words whitespace-pre-wrap'}>{text}</span>;
        }
    }
}

function Heading({ label, ignored, children }: { label: string; ignored: string[]; children?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line px-4 py-3">
            <h2 className="font-semibold">
                {label}
                {ignored.length > 0 && <span className="ml-2 text-xs font-normal text-muted">Not filtered by {ignored.join(', ')}</span>}
            </h2>
            {children}
        </div>
    );
}

const section = 'overflow-hidden rounded-md border border-line bg-surface';

/** Consecutive metrics share one strip. */
export function Metrics({ metrics, ignored }: { metrics: MetricWidget[]; ignored: (filters: string[]) => string[] }) {
    return (
        <section className={`${section} grid grid-cols-2 divide-line sm:flex sm:divide-x`}>
            {metrics.map((metric) => {
                const notes = ignored(metric.filters);
                return (
                    <div key={metric.key} className="min-w-0 flex-1 border-line px-4 py-3 max-sm:border-b max-sm:odd:border-r">
                        <div className="truncate text-muted" title={notes.length ? `Not filtered by ${notes.join(', ')}` : undefined}>
                            {metric.label}
                        </div>
                        <div className="mt-1 text-[28px] leading-none font-semibold tracking-tight tabular-nums">
                            {metric.value === null ? '–' : formatNumber(metric.value)}
                        </div>
                    </div>
                );
            })}
        </section>
    );
}

function Actions({ actions, row = {} }: { actions: Action[]; row?: Row }) {
    const client = useQueryClient();
    const [notice, setNotice] = useState<{ tone: Tone; text: string } | null>(null);
    const mutation = useMutation({
        mutationFn: (action: Action) => api(fill(action.url, row), { method: 'POST' }),
        onSuccess: (_, action) => setNotice({ tone: 'ok', text: `${action.label}: done.` }),
        onError: (error) => setNotice({ tone: 'error', text: error.message }),
        onSettled: () => client.invalidateQueries(),
    });

    return (
        <div className="flex items-center gap-2 whitespace-nowrap">
            {actions.map((action) => (
                <button
                    key={action.key}
                    type="button"
                    disabled={mutation.isPending}
                    onClick={() => mutation.mutate(action)}
                    className="rounded border border-line bg-surface px-2.5 py-1 font-medium hover:border-brass disabled:opacity-50"
                >
                    {action.label}
                </button>
            ))}
            {notice && (
                <span role="status" className={`text-xs ${notice.tone === 'ok' ? 'text-ok' : 'text-error'}`}>
                    {notice.text}
                </span>
            )}
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
        <section className={section}>
            <Heading label={widget.label} ignored={ignored}>
                {pagination?.total != null && <span className="text-muted tabular-nums">{formatNumber(pagination.total)} total</span>}
            </Heading>
            {widget.rows.length === 0 ? (
                <p className="px-4 py-6 text-muted">{filtered ? 'Nothing matches these filters.' : 'Nothing recorded yet.'}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-[13px]">
                        <thead className="text-muted">
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
                        <tbody className="divide-y divide-line border-t border-line">
                            {widget.rows.map((row, index) => (
                                <tr key={index} onClick={(event) => open(event, row)} className={`align-top ${link ? 'cursor-pointer hover:bg-bg' : ''}`}>
                                    {widget.columns.map((column, columnIndex) => (
                                        <td key={column.key} className={`px-4 py-2 ${alignRight(column) ? 'text-right' : ''}`}>
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
                                        <td className="px-4 py-1.5">
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
                <nav aria-label={`${widget.label} pages`} className="flex items-center justify-end gap-3 border-t border-line px-4 py-2">
                    <span className="text-muted tabular-nums">{pages ? `Page ${current} of ${pages}` : `Page ${current}`}</span>
                    <PageButton disabled={current <= 1} onClick={() => setParams({ page: current > 2 ? String(current - 1) : null })}>
                        Previous
                    </PageButton>
                    <PageButton disabled={!pagination.has_more} onClick={() => setParams({ page: String(current + 1) })}>
                        Next
                    </PageButton>
                </nav>
            )}
        </section>
    );
}

const alignRight = (field: Field) => field.type === 'number' || field.type === 'duration';

function PageButton({ disabled, onClick, children }: { disabled: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button type="button" disabled={disabled} onClick={onClick} className="rounded border border-line px-2.5 py-1 hover:border-brass disabled:opacity-40">
            {children}
        </button>
    );
}

export function Details({ widget }: { widget: DetailsWidget }) {
    return (
        <section className={section}>
            <Heading label={widget.label} ignored={[]}>
                {widget.actions.length > 0 && <Actions actions={widget.actions} />}
            </Heading>
            <dl className="grid gap-x-6 gap-y-3 px-4 py-4 sm:grid-cols-[minmax(8rem,max-content)_1fr]">
                {widget.items.map((item) => (
                    <div key={item.key} className="contents">
                        <dt className="text-muted">{item.label}</dt>
                        <dd className="min-w-0">
                            <Value field={item} value={item.value} />
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

const width = 800;
const height = 200;
const pad = { top: 12, right: 12, bottom: 24, left: 44 };

/** Lines over time. Missing points between the first and the last count as zero, at the smallest step between points. */
export function Series({ widget, ignored }: { widget: SeriesWidget; ignored: string[] }) {
    const [hover, setHover] = useState<number | null>(null);
    const points = densify(widget);
    const max = Math.max(1, ...points.flatMap((point) => widget.lines.map((line) => Number(point.values[line.key]) || 0)));
    const first = points[0]?.time ?? 0;
    const span = Math.max(1, (points.at(-1)?.time ?? 0) - first);
    const x = (time: number) => pad.left + ((time - first) / span) * (width - pad.left - pad.right);
    const y = (value: number) => height - pad.bottom - (value / max) * (height - pad.top - pad.bottom);
    const active = hover === null ? null : points[hover];

    const onMove = (event: MouseEvent<SVGSVGElement>) => {
        const box = event.currentTarget.getBoundingClientRect();
        const time = first + (((event.clientX - box.left) / box.width) * width - pad.left) / (width - pad.left - pad.right) * span;
        let nearest = 0;
        points.forEach((point, index) => {
            if (Math.abs(point.time - time) < Math.abs(points[nearest].time - time)) nearest = index;
        });
        setHover(nearest);
    };

    return (
        <section className={section}>
            <Heading label={widget.label} ignored={ignored}>
                <ul className="flex flex-wrap gap-x-4 gap-y-1 text-muted">
                    {widget.lines.map((line, index) => (
                        <li key={line.key} className="flex items-center gap-1.5">
                            <span className="h-0.5 w-3 rounded" style={{ background: `var(--series-${(index % 8) + 1})` }} />
                            {line.label}
                        </li>
                    ))}
                </ul>
            </Heading>
            {points.length === 0 ? (
                <p className="px-4 py-6 text-muted">Nothing recorded in this range.</p>
            ) : (
                <div className="relative px-2 py-2">
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
                                <line x1={pad.left} x2={width - pad.right} y1={y(max * ratio)} y2={y(max * ratio)} stroke="var(--line)" />
                                <text x={pad.left - 6} y={y(max * ratio) + 4} textAnchor="end" fontSize="11" fill="var(--muted)">
                                    {formatNumber(Math.round(max * ratio))}
                                </text>
                            </g>
                        ))}
                        {[points[0], points.at(-1)!].map((point, index) => (
                            <text key={index} x={x(point.time)} y={height - 6} textAnchor={index ? 'end' : 'start'} fontSize="11" fill="var(--muted)">
                                {formatDate(new Date(point.time).toISOString())}
                            </text>
                        ))}
                        {widget.lines.map((line, index) => (
                            <polyline
                                key={line.key}
                                fill="none"
                                stroke={`var(--series-${(index % 8) + 1})`}
                                strokeWidth="2"
                                strokeLinejoin="round"
                                vectorEffect="non-scaling-stroke"
                                points={points.map((point) => `${x(point.time)},${y(Number(point.values[line.key]) || 0)}`).join(' ')}
                            />
                        ))}
                        {active && <line x1={x(active.time)} x2={x(active.time)} y1={pad.top} y2={height - pad.bottom} stroke="var(--muted)" strokeDasharray="3 3" />}
                    </svg>
                    {active && (
                        <div
                            className="pointer-events-none absolute top-2 rounded border border-line bg-surface px-3 py-2 text-xs shadow-sm"
                            style={x(active.time) > width / 2 ? { right: `${(1 - x(active.time) / width) * 100 + 2}%` } : { left: `${(x(active.time) / width) * 100 + 2}%` }}
                        >
                            <div className="mb-1 font-medium">{formatDate(new Date(active.time).toISOString())}</div>
                            {widget.lines.map((line, index) => (
                                <div key={line.key} className="flex items-center justify-between gap-4">
                                    <span className="flex items-center gap-1.5 text-muted">
                                        <span className="size-2 rounded-full" style={{ background: `var(--series-${(index % 8) + 1})` }} />
                                        {line.label}
                                    </span>
                                    <span className="tabular-nums">{formatNumber(Number(active.values[line.key]) || 0)}</span>
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
