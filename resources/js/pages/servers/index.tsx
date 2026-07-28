import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Plus, Server as ServerIcon } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { index, show, store } from '@/routes/servers';
import type { ServerSummary } from '@/types';

const statusVariant = {
    online: 'default',
    offline: 'destructive',
    pending: 'secondary',
} as const;

const statusDot = {
    online: 'bg-emerald-500',
    offline: 'bg-red-500',
    pending: 'bg-muted-foreground/40',
} as const;

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = '';

    for (const next of units) {
        value /= 1024;
        unit = next;

        if (value < 1024) {
            break;
        }
    }

    return `${value.toFixed(1)} ${unit}`;
}

type Props = {
    servers: ServerSummary[];
};

export default function ServersIndex({ servers }: Props) {
    const { currentTeam } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="Servers" />

            <div className="flex flex-col space-y-6">
                <PageHeader
                    title="Servers"
                    description="Connect Linux servers and manage their log sources."
                    action={
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="size-4" /> Add server
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <Form
                                    key={String(open)}
                                    {...store.form({ current_team: teamSlug })}
                                    resetOnSuccess
                                    className="space-y-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Add server
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Name the server, then run
                                                    the install command shown on
                                                    the next screen.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <div className="space-y-2">
                                                <Label htmlFor="name">
                                                    Name
                                                </Label>
                                                <Input
                                                    id="name"
                                                    name="name"
                                                    placeholder="web-1"
                                                    autoFocus
                                                />
                                                <InputError
                                                    message={errors.name}
                                                />
                                            </div>
                                            <DialogFooter>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Create server
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    }
                />

                {servers.length === 0 ? (
                    <EmptyState
                        icon={ServerIcon}
                        title="No servers yet"
                        description="Connect your first server to start shipping logs."
                        action={
                            <Button onClick={() => setOpen(true)}>
                                <Plus className="size-4" /> Add server
                            </Button>
                        }
                    />
                ) : (
                    <div className="divide-y rounded-xl border">
                        {servers.map((server) => (
                            <Link
                                key={server.id}
                                href={show({
                                    current_team: teamSlug,
                                    server: server.id,
                                })}
                                className="flex items-center gap-3 p-4 hover:bg-muted/50"
                            >
                                <span
                                    className={cn(
                                        'size-2 shrink-0 rounded-full',
                                        statusDot[server.status],
                                    )}
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {server.name}
                                    </p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {server.hostname ?? 'Not connected yet'}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-4">
                                    <span className="text-sm text-muted-foreground">
                                        {formatBytes(server.bytesToday)} today
                                    </span>
                                    <Badge
                                        variant={statusVariant[server.status]}
                                    >
                                        {server.status}
                                    </Badge>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ServersIndex.layout = (
    props: Props & { currentTeam?: { slug: string } | null },
) => ({
    breadcrumbs: [
        {
            title: 'Servers',
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
