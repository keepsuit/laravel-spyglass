import { useSyncExternalStore } from 'react';

const root = document.getElementById('spyglass');

export const config = {
    base: root?.dataset.base ?? '',
    csrf: root?.dataset.csrf ?? '',
};

export type Tone = 'ok' | 'info' | 'warning' | 'error';

export type Field = { key: string; label: string; type: string; tones?: Record<string, Tone> };
export type Action = { key: string; label: string; url: string };
export type Filter = { key: string; label: string; value: string | null; options: { value: string; label: string }[] | null };
export type Row = Record<string, unknown>;

export type MetricWidget = { type: 'metric'; key: string; label: string; value: number | string | null; filters: string[] };
export type TableWidget = {
    type: 'table';
    key: string;
    label: string;
    columns: Field[];
    rows: Row[];
    pagination: { page: number; per_page: number; has_more: boolean; total: number | null } | null;
    link: string | null;
    actions: Action[];
    filters: string[];
};
export type SeriesWidget = {
    type: 'series';
    key: string;
    label: string;
    x: string;
    lines: { key: string; label: string }[];
    points: Row[];
    filters: string[];
};
export type DetailsWidget = { type: 'details'; key: string; label: string; items: (Field & { value: unknown })[]; actions: Action[]; filters: string[] };
export type Widget = MetricWidget | TableWidget | SeriesWidget | DetailsWidget;
export type Page = { title: string; filters: Filter[]; widgets: Widget[] };

export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
    ) {
        super(message);
    }
}

/** `path` is relative to the API root, which mirrors the dashboard's own paths. */
export async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await fetch(`${config.base}/api/${path}`, {
        ...init,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (response.status === 204) {
        return null as T;
    }

    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, errorMessage(response.status, body));
    }

    return body as T;
}

function errorMessage(status: number, body: { message?: string; errors?: Record<string, string[]> } | null): string {
    if (status === 401 || status === 403) return 'Access denied. Sign in again, then reload the page.';
    if (status === 419) return 'The session expired. Reload the page and try again.';
    if (status === 422 && body?.errors) return Object.values(body.errors).flat().join(' ');
    if (status === 404) return body?.message && body.message !== 'Not Found' ? body.message : 'This page does not exist, or the module is disabled.';

    return body?.message || `The request failed with status ${status}.`;
}

/** Fills `{column}` placeholders with the row's values. */
export function fill(template: string, row: Row): string {
    return template.replace(/\{(\w+)\}/g, (_, key: string) => encodeURIComponent(String(row[key] ?? '')));
}

const subscribe = (callback: () => void) => {
    window.addEventListener('popstate', callback);
    return () => window.removeEventListener('popstate', callback);
};

/** The dashboard path relative to the base, and the query string. */
export function useLocation(): { path: string; search: string } {
    const href = useSyncExternalStore(subscribe, () => window.location.pathname + window.location.search);
    const url = new URL(href, window.location.origin);

    return { path: url.pathname.slice(config.base.length).replace(/^\/+|\/+$/g, ''), search: url.search };
}

export function href(path: string): string {
    return `${config.base}/${path}`;
}

export function navigate(to: string, replace = false): void {
    window.history[replace ? 'replaceState' : 'pushState'](null, '', to);
    window.dispatchEvent(new PopStateEvent('popstate'));
}

/** Sets query string values, resetting the table page. */
export function setParams(values: Record<string, string | null>): void {
    const params = new URLSearchParams(window.location.search);

    if (!('page' in values)) params.delete('page');

    for (const [key, value] of Object.entries(values)) {
        value === null || value === '' ? params.delete(key) : params.set(key, value);
    }

    const search = params.toString();
    navigate(window.location.pathname + (search ? `?${search}` : ''));
}

const numberFormat = new Intl.NumberFormat();
const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' });

export function formatNumber(value: unknown): string {
    return typeof value === 'number' ? numberFormat.format(value) : String(value);
}

export function formatDate(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : dateFormat.format(date);
}

export function formatDuration(ms: number): string {
    if (ms < 1000) return `${ms} ms`;
    if (ms < 60_000) return `${(ms / 1000).toFixed(1)} s`;

    const minutes = Math.floor(ms / 60_000);
    if (minutes < 60) return `${minutes} min ${Math.round((ms % 60_000) / 1000)} s`;

    return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}
