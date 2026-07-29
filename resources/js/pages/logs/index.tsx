import { Head, usePage } from '@inertiajs/react';
import { Pause, Play, Search, SearchX } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import EmptyState from '@/components/empty-state';
import PageHeader from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { detectLevel } from '@/lib/log-level';
import { index, query as logsQuery } from '@/routes/logs';
import type { LogEntry } from '@/types';

type Props = {
    servers: { id: number; name: string }[];
    sources: string[];
};

const RANGES: Record<string, number> = {
    '15m': 15 * 60,
    '1h': 60 * 60,
    '24h': 24 * 60 * 60,
    '7d': 7 * 24 * 60 * 60,
};

function prettyLine(line: string): string | null {
    if (!line.startsWith('{') && !line.startsWith('[')) {
        return null;
    }

    try {
        return JSON.stringify(JSON.parse(line), null, 2);
    } catch {
        return null;
    }
}

function LogRow({ entry }: { entry: LogEntry }) {
    const [expanded, setExpanded] = useState(false);
    const detailsId = useId();
    const pretty = expanded ? prettyLine(entry.line) : null;
    const level = detectLevel(entry.line);

    return (
        <div className="border-b border-border/40 font-mono text-xs">
            <button
                type="button"
                className="flex min-h-11 w-full items-baseline gap-2 px-2 py-2 text-left hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-expanded={expanded}
                aria-controls={detailsId}
                onClick={() => setExpanded(!expanded)}
            >
                <span className="w-20 shrink-0 text-foreground/70">
                    {new Date(entry.time).toLocaleTimeString()}
                </span>
                {level === 'error' && (
                    <Badge variant="destructive" className="shrink-0">
                        error
                    </Badge>
                )}
                {level === 'warn' && (
                    <Badge variant="warning" className="shrink-0">
                        warn
                    </Badge>
                )}
                <span className="shrink-0 text-foreground/70">
                    {entry.labels.source}
                </span>
                <span className="min-w-0 flex-1 break-all whitespace-pre-wrap">
                    {entry.line}
                </span>
            </button>
            {expanded && (
                <div id={detailsId} className="space-y-1 px-2 pb-2 sm:pl-28">
                    <div className="flex flex-wrap gap-1">
                        {Object.entries(entry.labels).map(([key, value]) => (
                            <Badge key={key} variant="secondary">
                                {key}={value}
                            </Badge>
                        ))}
                    </div>
                    <pre className="overflow-x-auto rounded bg-muted p-2 whitespace-pre-wrap">
                        {pretty ?? entry.line}
                    </pre>
                </div>
            )}
        </div>
    );
}

export default function LogsIndex({ servers, sources }: Props) {
    const { currentTeam } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';

    // Seed filters from deep-link query params (e.g. from an alert).
    const deepLink = new URLSearchParams(window.location.search);
    const [serverId, setServerId] = useState<string>(
        () => deepLink.get('servers')?.split(',')[0] ?? 'all',
    );
    const [source, setSource] = useState<string>(
        () => deepLink.get('sources')?.split(',')[0] ?? 'all',
    );
    const [search, setSearch] = useState(() => deepLink.get('search') ?? '');
    const [range, setRange] = useState('1h');
    const [entries, setEntries] = useState<LogEntry[]>([]);
    const [loading, setLoading] = useState(false);
    const [searched, setSearched] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [live, setLive] = useState(false);
    const lastTs = useRef<string | null>(null);

    const fetchLogs = useCallback(
        async (since?: string | null) => {
            const params: Record<string, string> = {};

            if (serverId !== 'all') {
                params.servers = serverId;
            }

            if (source !== 'all') {
                params.sources = source;
            }

            if (search) {
                params.search = search;
            }

            if (since) {
                // Tail from the newest entry we have; ts is nanoseconds, start is seconds.
                params.start = String(BigInt(since) / 1_000_000_000n);
            } else {
                params.start = String(
                    Math.floor(Date.now() / 1000) - RANGES[range],
                );
            }

            const url =
                logsQuery({ current_team: teamSlug }).url +
                '?' +
                new URLSearchParams(params).toString();

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
            });
            const data = (await response.json()) as {
                entries: LogEntry[];
                error?: string;
            };

            if (!response.ok) {
                setError(data.error ?? 'Log storage is unavailable right now.');

                return data.entries ?? [];
            }

            setError(null);

            return data.entries;
        },
        [serverId, source, search, range, teamSlug],
    );

    const runSearch = async () => {
        setLoading(true);
        const result = await fetchLogs();
        setEntries(result);
        lastTs.current = result[0]?.ts ?? null;
        setLoading(false);
        setSearched(true);
    };

    // If we arrived with deep-link params, run the seeded search once on mount.
    useEffect(() => {
        if (
            deepLink.has('servers') ||
            deepLink.has('sources') ||
            deepLink.has('search')
        ) {
            // Defer so the mount effect doesn't setState synchronously.
            setTimeout(runSearch, 0);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!live) {
            return;
        }

        const interval = setInterval(async () => {
            const fresh = await fetchLogs(lastTs.current);

            if (fresh.length > 0) {
                setEntries((current) => {
                    const known = new Set(
                        current.map((entry) => entry.ts + entry.line),
                    );
                    const additions = fresh.filter(
                        (entry) => !known.has(entry.ts + entry.line),
                    );

                    if (additions.length === 0) {
                        return current;
                    }

                    lastTs.current = additions[0].ts;

                    return [...additions, ...current].slice(0, 1000);
                });
            }
        }, 2000);

        return () => clearInterval(interval);
    }, [live, fetchLogs]);

    return (
        <>
            <Head title="Logs" />

            <div className="flex h-full flex-col space-y-4 p-4">
                <PageHeader
                    title="Logs"
                    description="Search and live-tail across all your servers."
                />

                <div className="sticky top-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-b bg-background/95 px-4 py-3 backdrop-blur">
                    <Select value={serverId} onValueChange={setServerId}>
                        <SelectTrigger
                            className="w-44"
                            aria-label="Filter by server"
                        >
                            <SelectValue placeholder="All servers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All servers</SelectItem>
                            {servers.map((server) => (
                                <SelectItem
                                    key={server.id}
                                    value={String(server.id)}
                                >
                                    {server.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={source} onValueChange={setSource}>
                        <SelectTrigger
                            className="w-40"
                            aria-label="Filter by log source"
                        >
                            <SelectValue placeholder="All sources" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All sources</SelectItem>
                            {sources.map((name) => (
                                <SelectItem key={name} value={name}>
                                    {name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={range} onValueChange={setRange}>
                        <SelectTrigger
                            className="w-28"
                            aria-label="Log time range"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.keys(RANGES).map((key) => (
                                <SelectItem key={key} value={key}>
                                    Last {key}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) =>
                            event.key === 'Enter' && runSearch()
                        }
                        placeholder="Contains text…"
                        className="w-64"
                        aria-label="Log text"
                    />

                    <Button onClick={runSearch} disabled={loading}>
                        <Search className="size-4" /> Search
                    </Button>

                    <div className="flex items-center gap-2">
                        {live && (
                            <span className="size-2 animate-pulse rounded-full bg-primary shadow-glow" />
                        )}
                        <Button
                            variant={live ? 'default' : 'outline'}
                            onClick={() => {
                                if (!live && entries.length === 0) {
                                    runSearch();
                                }

                                setLive(!live);
                            }}
                        >
                            {live ? (
                                <>
                                    <Pause className="size-4" /> Pause
                                </>
                            ) : (
                                <>
                                    <Play className="size-4" /> Live
                                </>
                            )}
                        </Button>
                    </div>
                </div>

                {error && (
                    <p className="rounded-md border border-destructive/40 p-3 text-sm text-destructive">
                        {error}
                    </p>
                )}

                <div className="min-h-0 flex-1 overflow-y-auto rounded-xl border">
                    {entries.length === 0 ? (
                        loading ? (
                            <p className="p-8 text-center text-sm text-foreground/70">
                                Searching…
                            </p>
                        ) : searched ? (
                            <EmptyState
                                icon={SearchX}
                                title="No matching logs"
                                description="Try widening the time range or removing filters."
                            />
                        ) : (
                            <p className="p-8 text-center text-sm text-foreground/70">
                                Adjust filters and search.
                            </p>
                        )
                    ) : (
                        entries.map((entry) => (
                            <LogRow
                                key={entry.ts + entry.line.slice(0, 80)}
                                entry={entry}
                            />
                        ))
                    )}
                </div>
            </div>
        </>
    );
}

LogsIndex.layout = (
    props: Props & { currentTeam?: { slug: string } | null },
) => ({
    breadcrumbs: [
        {
            title: 'Logs',
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
