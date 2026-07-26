import { Form, Head, router, usePage } from '@inertiajs/react';
import { Bell, Plus, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    destroy as destroyAlert,
    index as alertsIndex,
    store as storeAlert,
    update as updateAlert,
} from '@/routes/alerts';
import {
    destroy as destroyChannel,
    store as storeChannel,
    update as updateChannel,
} from '@/routes/channels';
import type { AlertItem, AlertType, ChannelItem, ChannelType } from '@/types';

const alertTypeLabels: Record<AlertType, string> = {
    threshold: 'Log threshold',
    missing_logs: 'Missing logs',
};

const channelTypeLabels: Record<ChannelType, string> = {
    email: 'Email',
    webhook: 'Webhook',
    telegram: 'Telegram',
};

type ServerOption = { id: number; name: string };

type Props = {
    alerts: AlertItem[];
    channels: ChannelItem[];
    servers: ServerOption[];
    sources: string[];
};

function formatTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function AlertForm({
    action,
    method,
    alert,
    servers,
    sources,
    channels,
    onSuccess,
}: {
    action: string;
    method: 'post' | 'patch';
    alert?: AlertItem;
    servers: ServerOption[];
    sources: string[];
    channels: ChannelItem[];
    onSuccess: () => void;
}) {
    const [type, setType] = useState<AlertType>(alert?.type ?? 'threshold');
    const [selectedServers, setSelectedServers] = useState<number[]>(
        alert?.config.servers ?? [],
    );
    const [source, setSource] = useState<string>(
        alert?.config.sources[0] ?? 'all',
    );
    const [selectedChannels, setSelectedChannels] = useState<number[]>(
        alert?.channelIds ?? [],
    );

    const toggle = (
        id: number,
        setter: React.Dispatch<React.SetStateAction<number[]>>,
    ) => {
        setter((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

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
                    servers: selectedServers,
                    sources: source === 'all' ? [] : [source],
                    search:
                        type === 'missing_logs'
                            ? null
                            : (data.search as string) || null,
                    threshold:
                        type === 'missing_logs'
                            ? null
                            : Number(data.threshold),
                    window_minutes: Number(data.window_minutes),
                },
                channels: selectedChannels,
            })}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="alert-name">Name</Label>
                        <Input
                            id="alert-name"
                            name="name"
                            defaultValue={alert?.name}
                            placeholder="High error rate"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="alert-type">Type</Label>
                        <Select
                            value={type}
                            onValueChange={(value) =>
                                setType(value as AlertType)
                            }
                        >
                            <SelectTrigger id="alert-type" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(alertTypeLabels).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.type} />
                    </div>

                    <div className="space-y-2">
                        <Label>Servers (empty = all)</Label>
                        {servers.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No servers yet.
                            </p>
                        ) : (
                            <div className="space-y-2 rounded-md border p-3">
                                {servers.map((server) => (
                                    <div
                                        key={server.id}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`server-${server.id}`}
                                            checked={selectedServers.includes(
                                                server.id,
                                            )}
                                            onCheckedChange={() =>
                                                toggle(
                                                    server.id,
                                                    setSelectedServers,
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={`server-${server.id}`}
                                            className="font-normal"
                                        >
                                            {server.name}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="alert-source">Source</Label>
                        <Select value={source} onValueChange={setSource}>
                            <SelectTrigger id="alert-source" className="w-full">
                                <SelectValue />
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
                    </div>

                    {type === 'threshold' && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="alert-search">
                                    Search (empty = any log line)
                                </Label>
                                <Input
                                    id="alert-search"
                                    name="search"
                                    defaultValue={alert?.config.search ?? ''}
                                    placeholder="ERROR"
                                />
                                <InputError message={errors['config.search']} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="alert-threshold">
                                    Threshold (matches within the window)
                                </Label>
                                <Input
                                    id="alert-threshold"
                                    name="threshold"
                                    type="number"
                                    defaultValue={
                                        alert?.config.threshold ?? undefined
                                    }
                                    placeholder="10"
                                />
                                <InputError
                                    message={errors['config.threshold']}
                                />
                            </div>
                        </>
                    )}

                    <div className="space-y-2">
                        <Label htmlFor="alert-window">Window (minutes)</Label>
                        <Input
                            id="alert-window"
                            name="window_minutes"
                            type="number"
                            defaultValue={alert?.config.window_minutes ?? 5}
                        />
                        <InputError message={errors['config.window_minutes']} />
                    </div>

                    <div className="space-y-2">
                        <Label>Notify channels</Label>
                        {channels.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No channels yet — add one below.
                            </p>
                        ) : (
                            <div className="space-y-2 rounded-md border p-3">
                                {channels.map((channel) => (
                                    <div
                                        key={channel.id}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`notify-${channel.id}`}
                                            checked={selectedChannels.includes(
                                                channel.id,
                                            )}
                                            onCheckedChange={() =>
                                                toggle(
                                                    channel.id,
                                                    setSelectedChannels,
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={`notify-${channel.id}`}
                                            className="font-normal"
                                        >
                                            {channel.name}{' '}
                                            <span className="text-muted-foreground">
                                                (
                                                {channelTypeLabels[channel.type]}
                                                )
                                            </span>
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="alert-enabled"
                            name="enabled"
                            defaultChecked={alert?.enabled ?? true}
                        />
                        <Label htmlFor="alert-enabled">Enabled</Label>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {alert ? 'Save alert' : 'Add alert'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function AlertRow({
    alert,
    teamSlug,
    onEdit,
}: {
    alert: AlertItem;
    teamSlug: string;
    onEdit: () => void;
}) {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="p-4">
            <div className="flex items-center justify-between">
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    className="flex-1 text-left"
                >
                    <p className="font-medium">
                        {alert.name}
                        {!alert.enabled && (
                            <Badge variant="secondary" className="ml-2">
                                disabled
                            </Badge>
                        )}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {alertTypeLabels[alert.type]} · last change{' '}
                        {formatTime(alert.lastStateChangeAt)}
                    </p>
                </button>
                <div className="flex items-center gap-2">
                    <Badge
                        variant={
                            alert.state === 'firing' ? 'destructive' : 'default'
                        }
                    >
                        {alert.state}
                    </Badge>
                    <Button variant="outline" size="sm" onClick={onEdit}>
                        Edit
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            if (confirm(`Delete ${alert.name}?`)) {
                                router.delete(
                                    destroyAlert({
                                        current_team: teamSlug,
                                        alert: alert.id,
                                    }),
                                );
                            }
                        }}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            </div>

            {expanded && (
                <div className="mt-3 space-y-1">
                    {alert.events.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No recent state changes.
                        </p>
                    ) : (
                        alert.events.map((event, index) => (
                            <div
                                key={index}
                                className="flex items-center gap-3 text-sm"
                            >
                                <Badge
                                    variant={
                                        event.state === 'firing'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {event.state}
                                </Badge>
                                <span className="text-muted-foreground">
                                    measured {event.measuredValue}
                                </span>
                                <span className="text-muted-foreground">
                                    {new Date(
                                        event.createdAt,
                                    ).toLocaleString()}
                                </span>
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

function ChannelForm({
    action,
    method,
    channel,
    onSuccess,
}: {
    action: string;
    method: 'post' | 'patch';
    channel?: ChannelItem;
    onSuccess: () => void;
}) {
    const [type, setType] = useState<ChannelType>(channel?.type ?? 'email');

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
                    address:
                        type === 'email'
                            ? (data.address as string)
                            : undefined,
                    url: type === 'webhook' ? (data.url as string) : undefined,
                    chat_id:
                        type === 'telegram'
                            ? (data.chat_id as string)
                            : undefined,
                },
            })}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="channel-type">Type</Label>
                        <Select
                            value={type}
                            onValueChange={(value) =>
                                setType(value as ChannelType)
                            }
                        >
                            <SelectTrigger
                                id="channel-type"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(channelTypeLabels).map(
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
                        <Label htmlFor="channel-name">Name</Label>
                        <Input
                            id="channel-name"
                            name="name"
                            defaultValue={channel?.name}
                            placeholder="Ops email"
                        />
                        <InputError message={errors.name} />
                    </div>

                    {type === 'email' && (
                        <div className="space-y-2">
                            <Label htmlFor="channel-address">
                                Email address
                            </Label>
                            <Input
                                id="channel-address"
                                name="address"
                                type="email"
                                defaultValue={channel?.config.address}
                                placeholder="ops@example.com"
                            />
                            <InputError message={errors['config.address']} />
                        </div>
                    )}

                    {type === 'webhook' && (
                        <div className="space-y-2">
                            <Label htmlFor="channel-url">Webhook URL</Label>
                            <Input
                                id="channel-url"
                                name="url"
                                defaultValue={channel?.config.url}
                                placeholder="https://example.com/hooks/alerts"
                            />
                            <p className="text-xs text-muted-foreground">
                                POSTed as JSON:{' '}
                                {'{ alert, state, measuredValue, firedAt }'}
                            </p>
                            <InputError message={errors['config.url']} />
                        </div>
                    )}

                    {type === 'telegram' && (
                        <div className="space-y-2">
                            <Label htmlFor="channel-chat-id">Chat ID</Label>
                            <Input
                                id="channel-chat-id"
                                name="chat_id"
                                defaultValue={channel?.config.chat_id}
                                placeholder="123456789"
                            />
                            <p className="text-xs text-muted-foreground">
                                Message the bot, then paste your chat ID here.
                            </p>
                            <InputError message={errors['config.chat_id']} />
                        </div>
                    )}

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="channel-enabled"
                            name="enabled"
                            defaultChecked={channel?.enabled ?? true}
                        />
                        <Label htmlFor="channel-enabled">Enabled</Label>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {channel ? 'Save channel' : 'Add channel'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

export default function AlertsIndex({
    alerts,
    channels,
    servers,
    sources,
}: Props) {
    const { currentTeam } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';
    const [addAlertOpen, setAddAlertOpen] = useState(false);
    const [editingAlert, setEditingAlert] = useState<AlertItem | null>(null);
    const [addChannelOpen, setAddChannelOpen] = useState(false);
    const [editingChannel, setEditingChannel] = useState<ChannelItem | null>(
        null,
    );

    return (
        <>
            <Head title="Alerts" />

            <div className="flex flex-col space-y-8">
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="Alerts"
                            description="Get notified when logs cross a threshold or stop arriving."
                        />
                        <Dialog
                            open={addAlertOpen}
                            onOpenChange={setAddAlertOpen}
                        >
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="size-4" /> Add alert
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[85vh] overflow-y-auto">
                                <DialogHeader>
                                    <DialogTitle>Add alert</DialogTitle>
                                </DialogHeader>
                                <AlertForm
                                    action={
                                        storeAlert({ current_team: teamSlug })
                                            .url
                                    }
                                    method="post"
                                    servers={servers}
                                    sources={sources}
                                    channels={channels}
                                    onSuccess={() => setAddAlertOpen(false)}
                                />
                            </DialogContent>
                        </Dialog>
                    </div>

                    {alerts.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center">
                            <Bell className="size-8 text-muted-foreground" />
                            <p className="font-medium">No alerts yet</p>
                            <p className="text-sm text-muted-foreground">
                                Add an alert to watch your logs and get
                                notified.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y rounded-xl border">
                            {alerts.map((alert) => (
                                <AlertRow
                                    key={alert.id}
                                    alert={alert}
                                    teamSlug={teamSlug}
                                    onEdit={() => setEditingAlert(alert)}
                                />
                            ))}
                        </div>
                    )}

                    <Dialog
                        open={editingAlert !== null}
                        onOpenChange={(value) => !value && setEditingAlert(null)}
                    >
                        <DialogContent className="max-h-[85vh] overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Edit alert</DialogTitle>
                            </DialogHeader>
                            {editingAlert && (
                                <AlertForm
                                    action={
                                        updateAlert({
                                            current_team: teamSlug,
                                            alert: editingAlert.id,
                                        }).url
                                    }
                                    method="patch"
                                    alert={editingAlert}
                                    servers={servers}
                                    sources={sources}
                                    channels={channels}
                                    onSuccess={() => setEditingAlert(null)}
                                />
                            )}
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="Notification channels"
                            description="Where alerts are delivered when they fire."
                        />
                        <Dialog
                            open={addChannelOpen}
                            onOpenChange={setAddChannelOpen}
                        >
                            <DialogTrigger asChild>
                                <Button variant="outline">
                                    <Plus className="size-4" /> Add channel
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Add channel</DialogTitle>
                                </DialogHeader>
                                <ChannelForm
                                    action={
                                        storeChannel({ current_team: teamSlug })
                                            .url
                                    }
                                    method="post"
                                    onSuccess={() => setAddChannelOpen(false)}
                                />
                            </DialogContent>
                        </Dialog>
                    </div>

                    {channels.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center">
                            <Send className="size-8 text-muted-foreground" />
                            <p className="font-medium">No channels yet</p>
                            <p className="text-sm text-muted-foreground">
                                Add a channel so firing alerts can reach you.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y rounded-xl border">
                            {channels.map((channel) => (
                                <div
                                    key={channel.id}
                                    className="flex items-center justify-between p-4"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {channel.name}
                                            {channel.failureCount > 0 && (
                                                <Badge
                                                    variant="destructive"
                                                    className="ml-2"
                                                >
                                                    failing
                                                </Badge>
                                            )}
                                            {!channel.enabled &&
                                                channel.failureCount >= 10 && (
                                                    <Badge
                                                        variant="destructive"
                                                        className="ml-2"
                                                    >
                                                        disabled after repeated
                                                        failures
                                                    </Badge>
                                                )}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {channelTypeLabels[channel.type]}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setEditingChannel(channel)
                                            }
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        `Delete ${channel.name}?`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        destroyChannel({
                                                            current_team:
                                                                teamSlug,
                                                            channel: channel.id,
                                                        }),
                                                    );
                                                }
                                            }}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <Dialog
                        open={editingChannel !== null}
                        onOpenChange={(value) =>
                            !value && setEditingChannel(null)
                        }
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Edit channel</DialogTitle>
                            </DialogHeader>
                            {editingChannel && (
                                <ChannelForm
                                    action={
                                        updateChannel({
                                            current_team: teamSlug,
                                            channel: editingChannel.id,
                                        }).url
                                    }
                                    method="patch"
                                    channel={editingChannel}
                                    onSuccess={() => setEditingChannel(null)}
                                />
                            )}
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </>
    );
}

AlertsIndex.layout = (
    props: Props & { currentTeam?: { slug: string } | null },
) => ({
    breadcrumbs: [
        {
            title: 'Alerts',
            href: props.currentTeam ? alertsIndex(props.currentTeam.slug) : '/',
        },
    ],
});
