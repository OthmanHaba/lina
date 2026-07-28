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
    online: 'default',
    offline: 'destructive',
    pending: 'secondary',
} as const;

const typeLabels: Record<LogSourceType, string> = {
    systemd: 'systemd journal',
    docker: 'Docker containers',
    nginx: 'Nginx',
    file: 'Log files',
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
    synced: { dot: 'bg-emerald-500', label: 'In sync' },
    syncing: { dot: 'bg-amber-500', label: 'Syncing…' },
    unknown: { dot: 'bg-gray-400', label: 'Unknown' },
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
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
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
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
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
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            />
                            <InputError message={errors['config.paths']} />
                        </div>
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
                    <div className="space-y-2 rounded-xl border border-amber-400/50 bg-amber-50 p-4 dark:bg-amber-950/20">
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
                                <div
                                    key={source.id}
                                    className="flex items-center justify-between p-4"
                                >
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
                                            onClick={() => setEditing(source)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.delete(
                                                    destroySource({
                                                        current_team: teamSlug,
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
