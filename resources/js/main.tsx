import { Select } from '@base-ui/react/select';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { StrictMode, useEffect, useState, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import './app.css';
import { api, ApiError, href, navigate, setParams, useLocation, type Filter, type MetricWidget, type Page as PageData } from './lib';
import { Details, Link, Metrics, NoticeContext, Series, Table, type Notice } from './widgets';

const client = new QueryClient({
    defaultOptions: {
        queries: {
            // Paused by TanStack Query while the tab is hidden.
            refetchInterval: 60_000,
            staleTime: 30_000,
            retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
        },
    },
});

type Module = { key: string; label: string };

function App() {
    const { path, search } = useLocation();
    const modules = useQuery({ queryKey: ['modules'], queryFn: () => api<{ modules: Module[] }>(''), refetchInterval: false });
    const list = modules.data?.modules ?? [];
    const current = path.split('/')[0];

    useEffect(() => {
        if (path === '' && list.length > 0) navigate(href(list[0].key), true);
    }, [path, list]);

    return (
        <div className="min-h-screen md:grid md:grid-cols-[13rem_1fr]">
            <aside className="border-b border-line bg-surface md:sticky md:top-0 md:h-screen md:border-r md:border-b-0">
                <div className="flex items-center gap-2 px-4 pt-4 pb-2 md:px-5 md:pt-6 md:pb-5">
                    <svg viewBox="0 0 24 24" className="size-5 text-brass" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                        <circle cx="7" cy="17" r="4" />
                        <path d="M10 14 20 4M14 6l4 4" />
                    </svg>
                    <span className="text-lg font-semibold tracking-tight">Spyglass</span>
                </div>
                <nav aria-label="Modules" className="flex gap-1 overflow-x-auto px-2 pb-2 md:flex-col md:px-3">
                    {list.map((module) => (
                        <Link
                            key={module.key}
                            to={module.key}
                            className={`rounded px-3 py-1.5 whitespace-nowrap hover:bg-bg md:border-l-2 md:rounded-l-none ${
                                module.key === current ? 'bg-bg font-semibold md:border-brass' : 'text-muted md:border-transparent'
                            }`}
                        >
                            {module.label}
                        </Link>
                    ))}
                </nav>
            </aside>
            <main className="min-w-0 px-4 py-5 md:px-8 md:py-7">
                {modules.error ? (
                    <Problem error={modules.error} />
                ) : path === '' ? (
                    !modules.isPending && list.length === 0 && <p className="text-muted">All modules are disabled in config/spyglass.php.</p>
                ) : (
                    <Page key={path} path={path} search={search} module={list.find((module) => module.key === current)} />
                )}
            </main>
        </div>
    );
}

// Kept outside the page: it survives the move back to the list after the page it came from is gone.
let carried: Notice | null = null;

function Page({ path, search, module }: { path: string; search: string; module?: Module }) {
    const [notice, setNotice] = useState<Notice | null>(carried);

    useEffect(() => {
        carried = null;
    }, []);
    const query = useQuery({
        queryKey: ['page', path, search],
        queryFn: () => api<PageData>(path + search),
        // Keeps the page on screen while its filters change, not across pages.
        placeholderData: (previous, previousQuery) => (previousQuery?.queryKey[1] === path ? previous : undefined),
    });

    useEffect(() => {
        if (query.data) document.title = `${query.data.title} · Spyglass`;
    }, [query.data]);

    // A page that is gone (a failed job retried, a module disabled) is not shown with its stale data.
    const gone = query.error instanceof ApiError && query.error.status === 404;
    const page = gone ? undefined : query.data;

    // An action removed the page it was shown on (a failed job retried or deleted): back to the list.
    useEffect(() => {
        if (gone && notice?.tone === 'ok' && module && path !== module.key) {
            carried = notice;
            navigate(href(module.key), true);
        }
    }, [gone, notice, module, path]);
    const params = new URLSearchParams(search);
    const active = (page?.filters ?? []).filter((filter) => params.get(filter.key));
    const ignored = (filters: string[]) => active.filter((filter) => !filters.includes(filter.key)).map((filter) => filter.label);
    const pageParam = params.get('page');

    return (
        <div className="mx-auto max-w-[90rem] space-y-5">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    {path.includes('/') && module && (
                        <Link to={module.key} className="text-muted hover:text-ink">
                            {module.label}
                        </Link>
                    )}
                    <h1 className="truncate text-xl font-semibold tracking-tight">{page?.title ?? module?.label ?? ' '}</h1>
                </div>
                <div className="flex items-center gap-3 text-muted">
                    {query.dataUpdatedAt > 0 && (
                        <span className="tabular-nums">Updated {new Date(query.dataUpdatedAt).toLocaleTimeString()}</span>
                    )}
                    <button
                        type="button"
                        onClick={() => query.refetch()}
                        disabled={query.isFetching}
                        className="rounded border border-line bg-surface px-3 py-1.5 font-medium text-ink hover:border-brass disabled:opacity-60"
                    >
                        {query.isFetching ? 'Refreshing…' : 'Refresh'}
                    </button>
                </div>
            </header>

            {notice && (
                <p role="status" className={`rounded-md border bg-surface px-4 py-2 font-medium ${notice.tone === 'ok' ? 'border-ok/40 text-ok' : 'border-error/40 text-error'}`}>
                    {notice.text}
                </p>
            )}

            {page && page.filters.length > 0 && <Filters filters={page.filters} params={params} />}

            {query.error && !page ? (
                <Problem error={query.error} back={module} />
            ) : (
                <NoticeContext value={setNotice}>
                    {query.error && <Problem error={query.error} />}
                    {page && renderWidgets(page, ignored, active.length > 0, pageParam ? Number(pageParam) : null)}
                </NoticeContext>
            )}
        </div>
    );
}

function renderWidgets(page: PageData, ignored: (filters: string[]) => string[], filtered: boolean, pageParam: number | null): ReactNode[] {
    const nodes: ReactNode[] = [];
    let metrics: MetricWidget[] = [];

    const flush = () => {
        if (metrics.length) nodes.push(<Metrics key={`metrics-${nodes.length}`} metrics={metrics} ignored={ignored} />);
        metrics = [];
    };

    for (const widget of page.widgets) {
        if (widget.type === 'metric') {
            metrics.push(widget);
            continue;
        }

        flush();

        if (widget.type === 'table') nodes.push(<Table key={widget.key} widget={widget} ignored={ignored(widget.filters)} filtered={filtered} page={pageParam} />);
        if (widget.type === 'series') nodes.push(<Series key={widget.key} widget={widget} ignored={ignored(widget.filters)} />);
        if (widget.type === 'details') nodes.push(<Details key={widget.key} widget={widget} />);
    }

    flush();

    return nodes;
}

const control = 'h-8 rounded border border-line bg-surface px-2.5 text-ink';

function Filters({ filters, params }: { filters: Filter[]; params: URLSearchParams }) {
    const active = filters.some((filter) => params.get(filter.key));

    return (
        <div className="flex flex-wrap items-end gap-3">
            {filters.map((filter) =>
                filter.options ? (
                    <OptionFilter key={filter.key} filter={filter} fromUrl={params.get(filter.key)} />
                ) : (
                    <label key={`${filter.key}-${filter.value}`} className="flex flex-col gap-1 text-muted">
                        {filter.label}
                        <input
                            type="search"
                            defaultValue={filter.value ?? ''}
                            onKeyDown={(event) => event.key === 'Enter' && setParams({ [filter.key]: event.currentTarget.value.trim() })}
                            onBlur={(event) => event.currentTarget.value.trim() !== (filter.value ?? '') && setParams({ [filter.key]: event.currentTarget.value.trim() })}
                            className={`${control} w-44`}
                        />
                    </label>
                ),
            )}
            {active && (
                <button type="button" onClick={() => setParams(Object.fromEntries(filters.map((filter) => [filter.key, null])))} className="h-8 px-1 text-muted hover:text-ink">
                    Clear filters
                </button>
            )}
        </div>
    );
}

function OptionFilter({ filter, fromUrl }: { filter: Filter; fromUrl: string | null }) {
    // A value the URL did not set is the server's default: an "All" choice would only bring it back.
    const items = [...(filter.value !== null && fromUrl === null ? [] : [{ value: null, label: 'All' }]), ...filter.options!];

    return (
        <div className="flex flex-col gap-1 text-muted">
            <Select.Root items={items} value={filter.value} onValueChange={(value) => setParams({ [filter.key]: value as string | null })}>
                <Select.Label>{filter.label}</Select.Label>
                <Select.Trigger className={`${control} flex min-w-36 items-center justify-between gap-2 hover:border-brass`}>
                    <Select.Value />
                    <Select.Icon className="text-muted">▾</Select.Icon>
                </Select.Trigger>
                <Select.Portal>
                    <Select.Positioner sideOffset={4} className="z-10 outline-none">
                        <Select.Popup className="min-w-[var(--anchor-width)] rounded border border-line bg-surface py-1 text-ink shadow-md">
                            <Select.List>
                                {items.map((item) => (
                                    <Select.Item
                                        key={item.value ?? ''}
                                        value={item.value}
                                        className="flex cursor-default items-center gap-2 px-3 py-1.5 outline-none select-none data-highlighted:bg-bg data-selected:font-semibold"
                                    >
                                        <Select.ItemText>{item.label}</Select.ItemText>
                                    </Select.Item>
                                ))}
                            </Select.List>
                        </Select.Popup>
                    </Select.Positioner>
                </Select.Portal>
            </Select.Root>
        </div>
    );
}

function Problem({ error, back }: { error: Error; back?: Module }) {
    return (
        <div role="alert" className="rounded-md border border-error/40 bg-surface px-4 py-3">
            <p className="font-medium text-error">{error.message}</p>
            {back && (
                <Link to={back.key} className="mt-1 inline-block text-info hover:underline">
                    Back to {back.label}
                </Link>
            )}
        </div>
    );
}

createRoot(document.getElementById('spyglass')!).render(
    <StrictMode>
        <QueryClientProvider client={client}>
            <App />
        </QueryClientProvider>
    </StrictMode>,
);
