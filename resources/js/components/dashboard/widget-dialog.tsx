import { Form } from '@inertiajs/react';
import {
    Activity,
    AlarmClock,
    BarChart3,
    ListTree,
    ServerCog,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/dashboard/widgets';
import type { Widget, WidgetSize, WidgetType } from '@/types/dashboard';

type ServerOption = { id: number; name: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    teamSlug: string;
    servers: ServerOption[];
    sources: string[];
    widget: Widget | null;
};

const TYPE_OPTIONS: {
    value: WidgetType;
    label: string;
    description: string;
    icon: typeof BarChart3;
}[] = [
    {
        value: 'timeseries',
        label: 'Timeseries',
        description: 'Log volume over time',
        icon: BarChart3,
    },
    {
        value: 'stat',
        label: 'Stat',
        description: 'A single number',
        icon: Activity,
    },
    {
        value: 'log_stream',
        label: 'Log stream',
        description: 'Latest matching logs',
        icon: ListTree,
    },
    {
        value: 'alert_status',
        label: 'Alert status',
        description: 'Your alerts at a glance',
        icon: AlarmClock,
    },
    {
        value: 'server_health',
        label: 'Server health',
        description: 'Agent status',
        icon: ServerCog,
    },
];

const SIZE_OPTIONS: { value: WidgetSize; label: string }[] = [
    { value: 'third', label: 'Small (1/3)' },
    { value: 'half', label: 'Medium (1/2)' },
    { value: 'full', label: 'Full width' },
];

const WINDOW_OPTIONS = [
    { value: '15', label: '15 minutes' },
    { value: '30', label: '30 minutes' },
    { value: '60', label: '1 hour' },
    { value: '180', label: '3 hours' },
    { value: '360', label: '6 hours' },
    { value: '1440', label: '24 hours' },
];

const LIMIT_OPTIONS = [
    { value: '10', label: '10' },
    { value: '25', label: '25' },
    { value: '50', label: '50' },
    { value: '100', label: '100' },
];

function WidgetForm({
    type,
    teamSlug,
    servers,
    sources,
    widget,
    onSuccess,
}: {
    type: WidgetType;
    teamSlug: string;
    servers: ServerOption[];
    sources: string[];
    widget: Widget | null;
    onSuccess: () => void;
}) {
    const config = widget?.config ?? {};
    const [selectedServers, setSelectedServers] = useState<number[]>(
        config.server_ids ?? [],
    );
    const [selectedSources, setSelectedSources] = useState<string[]>(
        config.sources ?? [],
    );
    const [chart, setChart] = useState<'line' | 'bar' | 'area'>(
        config.chart ?? 'line',
    );
    const [groupBy, setGroupBy] = useState<string>(config.group_by ?? 'none');
    const [windowMinutes, setWindowMinutes] = useState<string>(
        String(config.window_minutes ?? 60),
    );
    const [limit, setLimit] = useState<string>(String(config.limit ?? 25));
    const [logql, setLogql] = useState<string>(config.logql ?? '');

    const toggleServer = (id: number) => {
        setSelectedServers((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

    const toggleSource = (name: string) => {
        setSelectedSources((current) =>
            current.includes(name)
                ? current.filter((value) => value !== name)
                : [...current, name],
        );
    };

    const hasLogql = logql.trim() !== '';
    const supportsLogql = type === 'timeseries' || type === 'stat';

    const formProps = widget
        ? update.form({ current_team: teamSlug, widget: widget.id })
        : store.form({ current_team: teamSlug });

    return (
        <Form
            {...formProps}
            onSuccess={onSuccess}
            transform={(data) => {
                const baseConfig: Record<string, unknown> = {
                    server_ids: selectedServers,
                    sources: selectedSources,
                    search: (data.search as string) || null,
                };

                if (type === 'timeseries') {
                    baseConfig.chart = chart;
                    baseConfig.group_by = groupBy === 'none' ? null : groupBy;
                    baseConfig.logql = hasLogql ? logql : null;
                } else if (type === 'stat') {
                    baseConfig.window_minutes = Number(windowMinutes);
                    baseConfig.logql = hasLogql ? logql : null;
                } else if (type === 'log_stream') {
                    baseConfig.limit = Number(limit);
                }

                return widget
                    ? {
                          title: data.title,
                          size: data.size,
                          config: baseConfig,
                      }
                    : {
                          type,
                          title: data.title,
                          size: data.size,
                          config: baseConfig,
                      };
            }}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="widget-title">Title</Label>
                        <Input
                            id="widget-title"
                            name="title"
                            defaultValue={widget?.title}
                            placeholder="Error rate"
                            required
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="widget-size">Size</Label>
                        <Select
                            name="size"
                            defaultValue={widget?.size ?? 'third'}
                        >
                            <SelectTrigger id="widget-size" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {SIZE_OPTIONS.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.size} />
                    </div>

                    <div className="space-y-2">
                        <Label>Servers (empty = all)</Label>
                        {servers.length === 0 ? (
                            <p className="text-sm text-foreground/70">
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
                                            id={`widget-server-${server.id}`}
                                            disabled={hasLogql}
                                            checked={selectedServers.includes(
                                                server.id,
                                            )}
                                            onCheckedChange={() =>
                                                toggleServer(server.id)
                                            }
                                        />
                                        <Label
                                            htmlFor={`widget-server-${server.id}`}
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
                        <Label>Sources (empty = all)</Label>
                        {sources.length === 0 ? (
                            <p className="text-sm text-foreground/70">
                                No sources yet.
                            </p>
                        ) : (
                            <div className="space-y-2 rounded-md border p-3">
                                {sources.map((sourceName) => (
                                    <div
                                        key={sourceName}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`widget-source-${sourceName}`}
                                            disabled={hasLogql}
                                            checked={selectedSources.includes(
                                                sourceName,
                                            )}
                                            onCheckedChange={() =>
                                                toggleSource(sourceName)
                                            }
                                        />
                                        <Label
                                            htmlFor={`widget-source-${sourceName}`}
                                            className="font-normal"
                                        >
                                            {sourceName}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="widget-search">Search</Label>
                        <Input
                            id="widget-search"
                            name="search"
                            disabled={hasLogql}
                            defaultValue={config.search ?? ''}
                            placeholder="ERROR"
                        />
                        <InputError message={errors['config.search']} />
                    </div>

                    {type === 'timeseries' && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="widget-chart">
                                    Chart style
                                </Label>
                                <Select
                                    value={chart}
                                    onValueChange={(value) =>
                                        setChart(
                                            value as 'line' | 'bar' | 'area',
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="widget-chart"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="line">
                                            Line
                                        </SelectItem>
                                        <SelectItem value="bar">Bar</SelectItem>
                                        <SelectItem value="area">
                                            Area
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors['config.chart']} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="widget-group-by">
                                    Group by
                                </Label>
                                <Select
                                    value={groupBy}
                                    onValueChange={setGroupBy}
                                >
                                    <SelectTrigger
                                        id="widget-group-by"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            None
                                        </SelectItem>
                                        <SelectItem value="server">
                                            Server
                                        </SelectItem>
                                        <SelectItem value="source">
                                            Source
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={errors['config.group_by']}
                                />
                            </div>
                        </>
                    )}

                    {type === 'stat' && (
                        <div className="space-y-2">
                            <Label htmlFor="widget-window">Window</Label>
                            <Select
                                value={windowMinutes}
                                onValueChange={setWindowMinutes}
                            >
                                <SelectTrigger
                                    id="widget-window"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {WINDOW_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={errors['config.window_minutes']}
                            />
                        </div>
                    )}

                    {type === 'log_stream' && (
                        <div className="space-y-2">
                            <Label htmlFor="widget-limit">Limit</Label>
                            <Select value={limit} onValueChange={setLimit}>
                                <SelectTrigger
                                    id="widget-limit"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {LIMIT_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors['config.limit']} />
                        </div>
                    )}

                    {supportsLogql && (
                        <Collapsible defaultOpen={hasLogql}>
                            <CollapsibleTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="px-0"
                                >
                                    Advanced — raw LogQL
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="space-y-2 pt-2">
                                <textarea
                                    id="widget-logql"
                                    rows={4}
                                    value={logql}
                                    onChange={(event) =>
                                        setLogql(event.target.value)
                                    }
                                    className={cn(
                                        'flex w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-foreground/70 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                                        'font-mono',
                                    )}
                                    placeholder='{source="nginx"} |= "500"'
                                />
                                <p className="text-xs text-foreground/70">
                                    When set, this query replaces the filters
                                    above. It runs inside your team's tenant
                                    only.
                                </p>
                                <InputError message={errors['config.logql']} />
                            </CollapsibleContent>
                        </Collapsible>
                    )}

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {widget ? 'Save widget' : 'Add widget'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

export default function WidgetDialog({
    open,
    onOpenChange,
    teamSlug,
    servers,
    sources,
    widget,
}: Props) {
    const [pickedType, setPickedType] = useState<WidgetType | null>(null);

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setPickedType(null);
        }
    };

    const type = widget?.type ?? pickedType;

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {widget ? 'Edit widget' : 'Add widget'}
                    </DialogTitle>
                </DialogHeader>

                {!widget && !type ? (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {TYPE_OPTIONS.map((option) => {
                            const Icon = option.icon;

                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setPickedType(option.value)}
                                    className="flex flex-col items-start gap-2 rounded-lg border p-4 text-left transition-colors hover:border-primary hover:bg-accent"
                                >
                                    <Icon className="size-5 text-foreground/70" />
                                    <span className="font-medium">
                                        {option.label}
                                    </span>
                                    <span className="text-sm text-foreground/70">
                                        {option.description}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    type && (
                        <WidgetForm
                            key={widget?.id ?? type}
                            type={type}
                            teamSlug={teamSlug}
                            servers={servers}
                            sources={sources}
                            widget={widget}
                            onSuccess={() => handleOpenChange(false)}
                        />
                    )
                )}
            </DialogContent>
        </Dialog>
    );
}
