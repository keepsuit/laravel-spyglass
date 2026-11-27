import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import type { DetailsWidget, Field, SeriesWidget, TableWidget } from './lib';
import { Details, NoticeContext, Series, Table, Value, type Notice } from './widgets';

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

    it('explains an empty table', () => {
        wrap(<Table widget={{ ...table, rows: [], pagination: null }} ignored={[]} filtered={false} page={null} />);
        expect(screen.getByText('Nothing recorded yet.')).toBeTruthy();
    });
});

describe('Series', () => {
    it('draws a line per series, filling missing points with zero', () => {
        const widget: SeriesWidget = {
            type: 'series',
            key: 'throughput',
            label: 'Throughput',
            x: 'bucket',
            lines: [
                { key: 'processed', label: 'Processed' },
                { key: 'failed', label: 'Failed' },
            ],
            points: [
                { bucket: '2026-01-01T12:00:00.000Z', processed: 4, failed: 1 },
                { bucket: '2026-01-01T12:15:00.000Z', processed: 2, failed: 0 },
                { bucket: '2026-01-01T12:05:00.000Z', processed: 3, failed: 0 },
            ],
            filters: [],
        };

        render(<Series widget={widget} ignored={[]} />);

        const lines = document.querySelectorAll('polyline');
        expect(lines).toHaveLength(2);
        expect(lines[0].getAttribute('points')?.split(' ')).toHaveLength(4);
        expect(screen.getByText('Processed')).toBeTruthy();
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
