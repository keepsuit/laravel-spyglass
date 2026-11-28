import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatRelative, formatShort, type DetailsWidget, type Field, type SeriesWidget, type TableWidget } from './lib';
import { bucketSize, Details, Group, merge, Metrics, NoticeContext, Series, Table, Value, type Notice } from './widgets';

afterEach(cleanup);

const wrap = (node: ReactNode) => render(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
const field = (type: string, extra: Partial<Field> = {}): Field => ({ key: 'v', label: 'V', type, ...extra });

describe('Value', () => {
    it.each([
        ['text', 'hello', 'hello'],
        ['number', 1234, new Intl.NumberFormat().format(1234)],
        ['duration', 1500, '1.5 s'],
        ['duration', 125_000, '2 min 5 s'],
        ['duration', 250, '250 ms'],
        ['boolean', true, 'Yes'],
        ['boolean', false, 'No'],
        ['text', null, '–'],
    ])('renders a %s value', (type, value, text) => {
        render(<Value field={field(type)} value={value} />);
        expect(document.body.textContent).toContain(text);
    });

    it('renders recent runs as a strip in their tones', () => {
        const runs = [
            { status: 'success', started_at: '2026-01-01T10:00:00.000Z' },
            { status: 'failed', started_at: '2026-01-01T11:00:00.000Z' },
        ];
        render(<Value field={field('runs', { tones: { success: 'ok', failed: 'error' } })} value={runs} />);

        const strip = screen.getByRole('img', { name: '2 recent runs, 1 not successful' });
        expect([...strip.children].map((run) => run.className.includes('bg-error'))).toEqual([false, true]);
    });

    it('renders a status with its tone', () => {
        render(<Value field={field('status', { tones: { failed: 'error' } })} value="failed" />);
        expect(screen.getByText('failed').className).toContain('text-error');
    });

    it('renders a link relative to the dashboard, or its label alone', () => {
        render(<Value field={field('link')} value={{ label: 'RuntimeException', url: 'exceptions/abc' }} />);
        expect(screen.getByRole('link', { name: 'RuntimeException' }).getAttribute('href')).toBe('/exceptions/abc');

        cleanup();
        render(<Value field={field('link')} value={{ label: 'RuntimeException', url: null }} />);
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('renders a label with its note and full name, and lines one per row', () => {
        render(<Value field={field('label')} value={{ text: 'ReportJob', note: 'daily', title: 'App\\Jobs\\ReportJob' }} />);
        expect(screen.getByText('daily').className).toContain('text-muted');
        expect(screen.getByTitle('App\\Jobs\\ReportJob')).toBeTruthy();

        cleanup();
        render(<Value field={field('lines')} value={['30 13 * * *', '30 19 * * *']} compact />);
        expect([...document.querySelectorAll('span.block > span')].map((line) => line.textContent)).toEqual(['30 13 * * *', '30 19 * * *']);
    });

    it('renders json, decoded or not', () => {
        render(<Value field={field('json')} value={{ tenant: 'a' }} />);
        expect(document.querySelector('pre')?.textContent).toContain('"tenant": "a"');

        cleanup();
        render(<Value field={field('json')} value={'{"cut'} />);
        expect(document.querySelector('pre')?.textContent).toBe('{"cut');
    });
});

const table: TableWidget = {
    type: 'table',
    key: 'failed_jobs',
    label: 'Failed jobs',
    columns: [{ key: 'job', label: 'Job', type: 'text' }, { key: 'failed_at', label: 'Failed at', type: 'datetime' }],
    rows: [{ uuid: 'a/b', job: 'App\\Jobs\\Sync', failed_at: '2026-01-01T12:00:00.000Z' }],
    pagination: { page: 1, per_page: 25, has_more: true, total: null },
    link: 'queue/failed-jobs/{uuid}',
    actions: [{ key: 'retry', label: 'Retry', url: 'queue/failed-jobs/{uuid}/retry' }],
    filters: [],
};

describe('Table', () => {
    it('renders columns, row links, actions and pagination', () => {
        wrap(<Table widget={table} ignored={['Job']} filtered page={null} />);

        expect(screen.getByRole('columnheader', { name: 'Failed at' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'App\\Jobs\\Sync' }).getAttribute('href')).toBe('/queue/failed-jobs/a%2Fb');
        expect(screen.getByRole('button', { name: 'Retry' })).toBeTruthy();
        expect(screen.getByText('Not filtered by Job')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Previous' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByRole('button', { name: 'Next' }) as HTMLButtonElement).disabled).toBe(false);
    });

    it('labels the cells after the first and marks the empty ones, for the layout of a phone', () => {
        wrap(<Table widget={{ ...table, rows: [{ ...table.rows[0], failed_at: null }] }} ignored={[]} filtered={false} page={null} />);

        const [title, date] = screen.getAllByRole('cell');
        expect([title.dataset.label, title.dataset.empty]).toEqual([undefined, undefined]);
        expect([date.dataset.label, date.dataset.empty]).toEqual(['Failed at', 'true']);
    });

    it('sorts the rows of an unpaginated table in the browser, empty values last', () => {
        const jobs: TableWidget = {
            ...table,
            key: 'jobs',
            columns: [{ key: 'job', label: 'Job', type: 'link', sortable: true }, { key: 'failed', label: 'Failed', type: 'number', sortable: true }],
            rows: [
                { job: { label: 'ReportJob', url: null }, failed: 2 },
                { job: { label: 'MailJob', url: null }, failed: null },
                { job: { label: 'SyncJob', url: null }, failed: 9 },
            ],
            pagination: null,
            link: null,
            actions: [],
        };
        const order = () => screen.getAllByRole('row').slice(1).map((row) => row.querySelector('td')?.textContent);

        wrap(<Table widget={jobs} ignored={[]} filtered={false} page={null} sort="jobs:-failed" />);
        expect(order()).toEqual(['SyncJob', 'ReportJob', 'MailJob']);
        expect(screen.getByRole('columnheader', { name: /Failed/ }).getAttribute('aria-sort')).toBe('descending');

        cleanup();
        wrap(<Table widget={jobs} ignored={[]} filtered={false} page={null} sort="jobs:job" />);
        expect(order()).toEqual(['MailJob', 'ReportJob', 'SyncJob']);

        fireEvent.click(screen.getByRole('button', { name: /Failed/ }));
        expect(new URLSearchParams(window.location.search).get('sort')).toBe('jobs:-failed');
    });

    it('explains an empty table', () => {
        wrap(<Table widget={{ ...table, rows: [], pagination: null }} ignored={[]} filtered={false} page={null} />);
        expect(screen.getByText('Nothing recorded yet.')).toBeTruthy();
    });
});

describe('Series', () => {
    const bars: SeriesWidget = {
        type: 'series',
        key: 'recorded',
        label: 'Log entries',
        x: 'bucket',
        lines: [
            { key: 'error', label: 'Errors', tone: 'error' },
            { key: 'warning', label: 'Warnings', tone: 'warning' },
        ],
        points: [
            { bucket: '2026-01-01T11:00:00.000Z', error: 2, warning: 1 },
            { bucket: '2026-01-01T12:00:00.000Z', error: 0, warning: 3 },
        ],
        filters: [],
        style: 'bars',
        note: 'At most one a minute.',
    };
    const fills = () => [...document.querySelectorAll('.ts-chart__bar path')].map((bar) => bar.getAttribute('fill'));

    it('picks the smallest nice bucket that fits, as a multiple of the step', () => {
        const fiveMinutes = 5 * 60_000;

        expect(bucketSize(288, fiveMinutes, 300)).toBe(fiveMinutes);
        expect(bucketSize(288, fiveMinutes, 100)).toBe(15 * 60_000);
        expect(bucketSize(288, fiveMinutes, 30)).toBe(60 * 60_000);
        // Never smaller than the step, whatever the room.
        expect(bucketSize(24, 3_600_000, 1000)).toBe(3_600_000);
    });

    it('sums neighbouring buckets, keeps totals and drops a partly covered first bucket', () => {
        const points = ['11:50', '11:55', '12:00', '12:05', '12:10', '12:15', '12:20', '12:25'].map((time, index) => ({ bucket: `2026-01-01T${time}:00.000Z`, error: index + 1 }));
        const merged = merge(points, 'bucket', ['error'], 15 * 60_000);

        // 11:45 holds only 11:50 and 11:55 of the range: dropped.
        expect(merged.map((point) => new Date(point.time).toISOString().slice(11, 16))).toEqual(['12:00', '12:15']);
        expect(merged.map((point) => point.values.error)).toEqual([3 + 4 + 5, 6 + 7 + 8]);
        expect(merged[0].end - merged[0].time).toBe(15 * 60_000);
    });

    it('draws a line per series in its colour', () => {
        render(<Series widget={{ ...bars, style: undefined, lines: [{ key: 'error', label: 'Errors' }, { key: 'warning', label: 'Warnings' }] }} ignored={[]} />);

        const strokes = [...document.querySelectorAll('.ts-chart__marks path')].map((path) => path.getAttribute('stroke'));
        expect(strokes).toContain('var(--series-1)');
        expect(strokes).toContain('var(--series-2)');
    });

    it('stacks bars in tone colours and shows or hides a series from the legend', () => {
        render(<Series widget={bars} ignored={[]} />);

        expect(fills().filter((fill) => fill === 'var(--warning)')).toHaveLength(2);
        expect(screen.getByText('At most one a minute.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Warnings' }));
        expect(screen.getByRole('button', { name: 'Warnings' }).getAttribute('aria-pressed')).toBe('false');
        expect(fills()).not.toContain('var(--warning)');
        expect(fills()).toContain('var(--error)');

        // Hiding the last visible series shows them all again.
        fireEvent.click(screen.getByRole('button', { name: 'Errors' }));
        expect(fills()).toContain('var(--warning)');
        expect(fills()).toContain('var(--error)');
    });
});

describe('Details', () => {
    it('renders its items and actions', () => {
        const widget: DetailsWidget = {
            type: 'details',
            key: 'group',
            label: 'Exception',
            items: [{ ...field('number'), label: 'Count', value: 3 }],
            actions: [{ key: 'resolve', label: 'Resolve', url: 'exceptions/abc/resolve' }],
            filters: [],
        };

        wrap(<Details widget={widget} />);

        expect(screen.getByText('Count')).toBeTruthy();
        expect(screen.getByText('3')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Resolve' })).toBeTruthy();
    });
});

describe('Actions', () => {
    it.each([
        [409, 'The command failed.'],
        [404, 'Already retried or removed.'],
    ])('reports a %i answer to the page with its message', async (status, message) => {
        const calls: string[] = [];
        const notices: Notice[] = [];
        globalThis.fetch = (async (url: string) => {
            calls.push(url);
            return new Response(JSON.stringify({ outcome: 'failed', message }), { status, headers: { 'Content-Type': 'application/json' } });
        }) as typeof fetch;

        wrap(
            <NoticeContext value={(notice) => notices.push(notice)}>
                <Table widget={{ ...table, rows: [table.rows[0], { ...table.rows[0], uuid: 'c' }] }} ignored={[]} filtered={false} page={null} />
            </NoticeContext>,
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'Retry' })[0]);

        await waitFor(() => expect(notices).toEqual([{ tone: 'error', text: message }]));
        expect(calls).toEqual(['/api/queue/failed-jobs/a%2Fb/retry']);
        expect(screen.queryByText(message)).toBeNull();
    });
});

describe('Metrics', () => {
    it('links a metric to its page and flags its tone', () => {
        render(
            <Metrics
                metrics={[
                    { type: 'metric', key: 'failed', label: 'Failed jobs', value: 9, filters: [], link: 'queue', tone: 'error' },
                    { type: 'metric', key: 'processed', label: 'Processed', value: 1200, filters: [] },
                ]}
                ignored={() => []}
            />,
        );

        expect(screen.getByRole('link', { name: /Failed jobs/ }).getAttribute('href')).toBe('/queue');
        expect(screen.getByText('9').className).toContain('text-error');
        expect(screen.queryByRole('link', { name: /Processed/ })).toBeNull();
    });
});

describe('Group', () => {
    it('draws its widgets in one card, under its heading and link', () => {
        const table: TableWidget = { type: 'table', key: 'jobs', label: 'Latest failed jobs', columns: [field('text')], rows: [{ v: 'A' }], pagination: null, link: null, actions: [], filters: [] };
        const { container } = wrap(
            <Group widget={{ type: 'group', key: 'queue', label: 'Queue', widgets: [table], filters: [], more: 'queue' }}>
                <Table widget={table} ignored={[]} filtered={false} page={null} />
            </Group>,
        );

        expect(screen.getByRole('heading', { level: 2, name: 'Queue' })).toBeTruthy();
        expect(screen.getByRole('heading', { level: 3, name: 'Latest failed jobs' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'View all' }).getAttribute('href')).toBe('/queue');
        expect(container.querySelectorAll('section.rounded-\\[1\\.25rem\\]')).toHaveLength(1);
    });
});

describe('Table options', () => {
    it('links to the full list and explains its own emptiness', () => {
        wrap(<Table widget={{ ...table, rows: [], pagination: null, more: 'queue', empty: 'No failed jobs.' }} ignored={[]} filtered={false} page={null} />);

        expect(screen.getByRole('link', { name: 'View all' }).getAttribute('href')).toBe('/queue');
        expect(screen.getByText('No failed jobs.')).toBeTruthy();
    });
});

describe('Datetimes', () => {
    afterEach(() => vi.useRealTimers());

    it('shows the relative time with the exact one beside it', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-01-01T12:05:00.000Z'));

        render(<Value field={field('datetime')} value="2026-01-01T12:00:00.000Z" compact />);

        const time = document.querySelector('time')!;
        expect(time.getAttribute('dateTime')).toBe('2026-01-01T12:00:00.000Z');
        expect(time.textContent).toContain(new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(-5, 'minute'));
        expect(time.textContent).toContain(formatShort('2026-01-01T12:00:00.000Z'));
    });

    it('reads future times too', () => {
        expect(formatRelative('2026-01-01T14:00:00.000Z', Date.parse('2026-01-01T12:00:00.000Z'))).toBe(
            new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(2, 'hour'),
        );
    });
});
