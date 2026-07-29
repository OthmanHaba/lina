import { Form, Head, router, usePage } from '@inertiajs/react';
import { Copy, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import DailyBarChart from '@/components/charts/daily-bar-chart';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { query as logsQuery } from '@/routes/logs';
import { destroy, index, show, token, update } from '@/routes/servers';
import {
    destroy as destroySource,
    store as storeSource,
    update as updateSource,
} from '@/routes/servers/sources';
import type {
    LogSourceItem,
    LogSourceType,
    ServerDetail,
    ServerHealth,
} from '@/types';
import { formatBytes } from './index';

const statusVariant = {
    online: 'success',
    offline: 'destructive',
    pending: 'warning',
} as const;

const typeLabels: Record<LogSourceType, string> = {
    systemd: 'systemd journal',
    docker: 'Docker containers',
    nginx: 'Nginx',
    file: 'Log files',
    container: 'File inside a container',
};

type Props = {
    server: ServerDetail;
    health: ServerHealth;
    sources: LogSourceItem[];
    plainToken: string | null;
    installBaseUrl: string;
    teamRetentionDays: number;
};

const configStatusMeta = {
    synced: { dot: 'bg-success', label: 'In sync' },
    syncing: { dot: 'bg-warning', label: 'Syncing…' },
    unknown: { dot: 'bg-muted-foreground/40', label: 'Unknown' },
} as const;

function splitLines(value: unknown): string[] | undefined {
    if (typeof value !== 'string' || value.trim() === '') {
        return undefined;
    }

    return value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
}

function SourceForm({
    action,
    method,
    source,
    onSuccess,
}: {
    action: string;
    method: 'post' | 'patch';
    source?: LogSourceItem;
    onSuccess: () => void;
}) {
    const [type, setType] = useState<LogSourceType>(source?.type ?? 'systemd');
    const [inContainerPath, setInContainerPath] = useState(
        source?.config.in_container_path ?? '',
    );

    return (
        <Form
            action={action}
            method={method}
            onSuccess={onSuccess}
            transform={(data) => ({
                name: data.name,
                type,
                enabled: data.enabled === 'on' || data.enabled === true,
                config: {
                    units: splitLines(data['config_units']),
                    containers: splitLines(data['config_containers']),
                    access_log:
                        (data['config_access_log'] as string) || undefined,
                    error_log:
                        (data['config_error_log'] as string) || undefined,
                    paths: splitLines(data['config_paths']),
                    container: data['config_container'],
                    in_container_path: data['config_in_container_path'],
                },
            })}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="source-type">Type</Label>
                        <Select
                            value={type}
                            onValueChange={(value) =>
                                setType(value as LogSourceType)
                            }
                        >
                            <SelectTrigger id="source-type" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(typeLabels).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="source-name">Name</Label>
                        <Input
                            id="source-name"
                            name="name"
                            defaultValue={source?.name}
                            placeholder="app-logs"
                        />
                        <InputError message={errors.name} />
                    </div>

                    {type === 'systemd' && (
                        <div className="space-y-2">
                            <Label htmlFor="config_units">
                                Units (one per line, empty = whole journal)
                            </Label>
                            <textarea
                                id="config_units"
                                name="config_units"
                                defaultValue={source?.config.units?.join('\n')}
                                placeholder={
                                    'nginx.service\nphp8.4-fpm.service'
                                }
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm"
                            />
                        </div>
                    )}

                    {type === 'docker' && (
                        <div className="space-y-2">
                            <Label htmlFor="config_containers">
                                Container names (one per line, empty = all)
                            </Label>
                            <textarea
                                id="config_containers"
                                name="config_containers"
                                defaultValue={source?.config.containers?.join(
                                    '\n',
                                )}
                                placeholder={'web\nworker'}
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm"
                            />
                        </div>
                    )}

                    {type === 'nginx' && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="config_access_log">
                                    Access log path
                                </Label>
                                <Input
                                    id="config_access_log"
                                    name="config_access_log"
                                    defaultValue={
                                        source?.config.access_log ??
                                        '/var/log/nginx/access.log'
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="config_error_log">
                                    Error log path
                                </Label>
                                <Input
                                    id="config_error_log"
                                    name="config_error_log"
                                    defaultValue={
                                        source?.config.error_log ??
                                        '/var/log/nginx/error.log'
                                    }
                                />
                            </div>
                        </>
                    )}

                    {type === 'file' && (
                        <div className="space-y-2">
                            <Label htmlFor="config_paths">
                                Paths or globs (one per line)
                            </Label>
                            <textarea
                                id="config_paths"
                                name="config_paths"
                                defaultValue={source?.config.paths?.join('\n')}
                                placeholder="/var/log/app/*.log"
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm"
                            />
                            <InputError message={errors['config.paths']} />
                        </div>
                    )}

                    {type === 'container' && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="config_container">
                                    Container name
                                </Label>
                                <Input
                                    id="config_container"
                                    name="config_container"
                                    defaultValue={source?.config.container}
                                    placeholder="store-app"
                                />
                                <InputError
                                    message={errors['config.container']}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="config_in_container_path">
                                    Log directory inside the container
                                </Label>
                                <Input
                                    id="config_in_container_path"
                                    name="config_in_container_path"
                                    value={inContainerPath}
                                    onChange={(e) =>
                                        setInContainerPath(e.target.value)
                                    }
                                    placeholder="/var/www/html/storage/logs"
                                />
                                <InputError
                                    message={errors['config.in_container_path']}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Directory only — no <code>/*.log</code>.
                                    Lina's agent runs on your host and can't
                                    read inside a container, so after you save
                                    you'll get one-time terminal commands to
                                    mount this directory to the host.
                                </p>
                                {inContainerPath.trim() !== '' && (
                                    <p className="text-xs text-muted-foreground">
                                        Lina will tail{' '}
                                        <code className="break-all">
                                            {inContainerPath.replace(
                                                /\/+$/,
                                                '',
                                            )}
                                            /*.log
                                        </code>{' '}
                                        once mounted. Exact host path shown
                                        after you save.
                                    </p>
                                )}
                            </div>
                        </>
                    )}

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="source-enabled"
                            name="enabled"
                            defaultChecked={source?.enabled ?? true}
                        />
                        <Label htmlFor="source-enabled">Enabled</Label>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {source ? 'Save source' : 'Add source'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function ContainerMountPanel({
    server,
    source,
    mountInstructions,
    teamSlug,
}: {
    server: ServerDetail;
    source: LogSourceItem;
    mountInstructions: NonNullable<LogSourceItem['mountInstructions']>;
    teamSlug: string;
}) {
    const [checking, setChecking] = useState(false);
    const [result, setResult] = useState<'found' | 'empty' | null>(null);

    const checkForLogs = async () => {
        setChecking(true);
        setResult(null);

        const params = new URLSearchParams({
            servers: String(server.id),
            sources: source.name,
        });
        const url =
            logsQuery({ current_team: teamSlug }).url + '?' + params.toString();

        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
        const data = (await response.json()) as { entries: unknown[] };

        setResult(data.entries?.length > 0 ? 'found' : 'empty');
        setChecking(false);
    };

    return (
        <div className="mt-3 space-y-3 rounded-lg border bg-muted/30 p-3 text-sm">
            <p className="text-xs text-muted-foreground">
                Lina's agent runs on your host and can't read files inside a
                container. These one-time steps expose the log directory to the
                host so the agent can tail it.
            </p>

            <div className="space-y-1">
                <p className="text-xs font-medium">
                    1. Prepare the host folder
                </p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.prepare}
                </p>
                <p className="text-xs text-muted-foreground">
                    So the container can write logs here and the agent can read
                    them.
                </p>
            </div>

            <div className="space-y-1">
                <p className="text-xs font-medium">
                    2. Add the mount and recreate the container
                </p>
                <p className="text-xs text-muted-foreground">
                    docker compose — add under the service's{' '}
                    <code>volumes:</code>, then recreate:
                </p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.compose}
                </p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.recreate}
                </p>
                <p className="text-xs text-muted-foreground">
                    or docker run — stop, remove, and re-run the container with:
                </p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.dockerRun}
                </p>
                <p className="text-xs text-muted-foreground">
                    On a managed platform, add the same bind mount in its UI and
                    redeploy.
                </p>
            </div>

            <div className="space-y-1">
                <p className="text-xs font-medium">3. Verify</p>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={checkForLogs}
                        disabled={checking}
                    >
                        Check for logs
                    </Button>
                    {result === 'found' && (
                        <span className="text-success">Logs are arriving</span>
                    )}
                    {result === 'empty' && (
                        <span className="text-muted-foreground">
                            No logs yet — apply the steps above and recreate the
                            container.
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}

function RetentionCard({
    server,
    teamRetentionDays,
    teamSlug,
}: {
    server: ServerDetail;
    teamRetentionDays: number;
    teamSlug: string;
}) {
    const [mode, setMode] = useState<'default' | 'custom'>(
        server.retentionDays === null ? 'default' : 'custom',
    );
    const [days, setDays] = useState(server.retentionDays ?? teamRetentionDays);
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.patch(
            update({ current_team: teamSlug, server: server.id }).url,
            { retention_days: mode === 'default' ? null : days },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <div className="space-y-3">
            <Heading variant="small" title="Retention" />

            <div className="space-y-4 rounded-xl border p-4">
                <div className="grid gap-2 sm:max-w-xs">
                    <Label htmlFor="retention-mode">Log retention</Label>
                    <Select
                        value={mode}
                        onValueChange={(value) =>
                            setMode(value as 'default' | 'custom')
                        }
                    >
                        <SelectTrigger id="retention-mode" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="default">
                                Team default ({teamRetentionDays} days)
                            </SelectItem>
                            <SelectItem value="custom">Custom</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {mode === 'custom' && (
                    <div className="grid gap-2 sm:max-w-xs">
                        <Label htmlFor="retention-days">Days</Label>
                        <Input
                            id="retention-days"
                            type="number"
                            min={1}
                            max={365}
                            value={days}
                            onChange={(event) =>
                                setDays(Number(event.target.value))
                            }
                        />
                    </div>
                )}

                <p className="text-sm text-muted-foreground">
                    How long logs are kept. Lowering this permanently deletes
                    logs older than the new value.
                </p>

                <Button onClick={save} disabled={saving}>
                    Save
                </Button>
            </div>
        </div>
    );
}

export default function ServerShow({
    server,
    health,
    sources,
    plainToken,
    installBaseUrl,
    teamRetentionDays,
}: Props) {
    const { currentTeam } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';
    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState<LogSourceItem | null>(null);
    const [copied, setCopied] = useState(false);

    const installCommand = plainToken
        ? `curl -sSL ${installBaseUrl}/${plainToken} | sudo bash`
        : null;

    const copyCommand = async () => {
        if (!installCommand) {
            return;
        }

        await navigator.clipboard.writeText(installCommand);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title={server.name} />

            <div className="flex flex-col space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={server.name}
                        description={server.hostname ?? 'Not connected yet'}
                    />
                    <Badge variant={statusVariant[server.status]}>
                        {server.status}
                    </Badge>
                </div>

                {installCommand && (
                    <div className="space-y-2 rounded-xl border border-warning/40 bg-warning/10 p-4">
                        <p className="font-medium">
                            Run this on the server as root — the token is shown
                            only once:
                        </p>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 overflow-x-auto rounded bg-background p-2 font-mono text-sm">
                                {installCommand}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={copyCommand}
                            >
                                <Copy className="size-4" />
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                )}

                <div className="grid gap-4 text-sm md:grid-cols-3">
                    <Card className="gap-1 py-4">
                        <CardHeader className="px-4">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Last seen
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-4">
                            <p className="font-medium">
                                {server.lastSeenAt
                                    ? new Date(
                                          server.lastSeenAt,
                                      ).toLocaleString()
                                    : 'Never'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="gap-1 py-4">
                        <CardHeader className="px-4">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Agent
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-4">
                            <p className="font-medium">
                                {server.agentVersion ?? 'Unknown'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="gap-1 py-4">
                        <CardHeader className="px-4">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Ingested today
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-4">
                            <p className="font-medium">
                                {formatBytes(server.bytesToday)}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-3">
                    <Heading variant="small" title="Agent health" />

                    <div className="grid gap-4 text-sm md:grid-cols-3">
                        <Card className="gap-1 py-4">
                            <CardHeader className="px-4">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Last config poll
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="px-4">
                                <p className="font-medium">
                                    {health.lastPollAt
                                        ? new Date(
                                              health.lastPollAt,
                                          ).toLocaleString()
                                        : 'Never'}
                                </p>
                            </CardContent>
                        </Card>
                        <Card className="gap-1 py-4">
                            <CardHeader className="px-4">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Last log push
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="px-4">
                                <p className="font-medium">
                                    {health.lastPushAt
                                        ? new Date(
                                              health.lastPushAt,
                                          ).toLocaleString()
                                        : 'Never'}
                                </p>
                            </CardContent>
                        </Card>
                        <Card className="gap-1 py-4">
                            <CardHeader className="px-4">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Config
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="px-4">
                                <p className="flex items-center gap-2 font-medium">
                                    <span
                                        className={`inline-block size-2 rounded-full ${configStatusMeta[health.configStatus].dot}`}
                                    />
                                    {
                                        configStatusMeta[health.configStatus]
                                            .label
                                    }
                                </p>
                                {health.configStatus === 'synced' && (
                                    <p className="text-muted-foreground">
                                        {health.configSyncedAt
                                            ? new Date(
                                                  health.configSyncedAt,
                                              ).toLocaleString()
                                            : null}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card className="gap-2 py-4">
                        <CardHeader className="px-4">
                            <CardTitle className="text-sm font-medium">
                                Ingest, last 14 days
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-4">
                            {health.usage.every((day) => day.bytes === 0) ? (
                                <p className="text-sm text-muted-foreground">
                                    No ingest in the last 14 days
                                </p>
                            ) : (
                                <DailyBarChart
                                    data={health.usage.map((day) => ({
                                        label: new Date(
                                            day.date,
                                        ).toLocaleDateString(undefined, {
                                            month: 'short',
                                            day: 'numeric',
                                        }),
                                        value: day.bytes,
                                    }))}
                                    formatValue={formatBytes}
                                />
                            )}
                        </CardContent>
                    </Card>
                </div>

                <RetentionCard
                    server={server}
                    teamRetentionDays={teamRetentionDays}
                    teamSlug={teamSlug}
                />

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="Log sources"
                            description="What this server collects. Changes reach the agent within a minute."
                        />
                        <Dialog open={addOpen} onOpenChange={setAddOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline">
                                    <Plus className="size-4" /> Add source
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Add log source</DialogTitle>
                                </DialogHeader>
                                <SourceForm
                                    action={
                                        storeSource({
                                            current_team: teamSlug,
                                            server: server.id,
                                        }).url
                                    }
                                    method="post"
                                    onSuccess={() => setAddOpen(false)}
                                />
                            </DialogContent>
                        </Dialog>
                    </div>

                    {sources.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No sources yet — this server isn't collecting
                            anything.
                        </p>
                    ) : (
                        <div className="divide-y rounded-xl border">
                            {sources.map((source) => (
                                <div key={source.id} className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="font-medium">
                                                {source.name}
                                                {!source.enabled && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="ml-2"
                                                    >
                                                        disabled
                                                    </Badge>
                                                )}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {typeLabels[source.type]}
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(source)
                                                }
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        destroySource({
                                                            current_team:
                                                                teamSlug,
                                                            server: server.id,
                                                            source: source.id,
                                                        }),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    {source.type === 'container' &&
                                        source.mountInstructions && (
                                            <ContainerMountPanel
                                                server={server}
                                                source={source}
                                                mountInstructions={
                                                    source.mountInstructions
                                                }
                                                teamSlug={teamSlug}
                                            />
                                        )}
                                </div>
                            ))}
                        </div>
                    )}

                    <Dialog
                        open={editing !== null}
                        onOpenChange={(value) => !value && setEditing(null)}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Edit log source</DialogTitle>
                            </DialogHeader>
                            {editing && (
                                <SourceForm
                                    action={
                                        updateSource({
                                            current_team: teamSlug,
                                            server: server.id,
                                            source: editing.id,
                                        }).url
                                    }
                                    method="patch"
                                    source={editing}
                                    onSuccess={() => setEditing(null)}
                                />
                            )}
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="space-y-3 rounded-xl border border-destructive/40 p-4">
                    <Heading
                        variant="small"
                        title="Danger zone"
                        description="Token rotation breaks the current agent until reinstalled; deletion is permanent."
                    />
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    token({
                                        current_team: teamSlug,
                                        server: server.id,
                                    }),
                                )
                            }
                        >
                            <RefreshCw className="size-4" /> Regenerate token
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (
                                    confirm(
                                        `Delete ${server.name}? Its agent stops working immediately.`,
                                    )
                                ) {
                                    router.delete(
                                        destroy({
                                            current_team: teamSlug,
                                            server: server.id,
                                        }),
                                    );
                                }
                            }}
                        >
                            <Trash2 className="size-4" /> Delete server
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}

ServerShow.layout = (
    props: Props & { currentTeam?: { slug: string } | null },
) => ({
    breadcrumbs: [
        {
            title: 'Servers',
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
        {
            title: props.server.name,
            href: props.currentTeam
                ? show({
                      current_team: props.currentTeam.slug,
                      server: props.server.id,
                  })
                : '/',
        },
    ],
});
