import { closestCenter, DndContext } from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    arrayMove,
    rectSortingStrategy,
    SortableContext,
    useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, router, usePage } from '@inertiajs/react';
import { LayoutDashboard, Plus, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import WidgetBody from '@/components/dashboard/widget-body';
import WidgetDialog from '@/components/dashboard/widget-dialog';
import WidgetFrame from '@/components/dashboard/widget-frame';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { destroy, reorder, update } from '@/routes/dashboard/widgets';
import type { DashboardInvitation } from '@/types';
import type { TimeRange, Widget, WidgetSize } from '@/types/dashboard';

const SIZE_CLASSES = {
    third: 'lg:col-span-2',
    half: 'lg:col-span-3',
    full: 'lg:col-span-6',
} as const;

const RANGES: { value: TimeRange; label: string }[] = [
    { value: '15m', label: 'Last 15 min' },
    { value: '1h', label: 'Last hour' },
    { value: '6h', label: 'Last 6 hours' },
    { value: '24h', label: 'Last 24 hours' },
    { value: '7d', label: 'Last 7 days' },
];

type Props = {
    widgets: Widget[];
    servers: { id: number; name: string }[];
    sources: string[];
    pendingInvitations?: DashboardInvitation[];
};

function SortableWidget({
    widget,
    children,
}: {
    widget: Widget;
    children: (dragHandleProps: Record<string, unknown>) => ReactNode;
}) {
    const { setNodeRef, transform, transition, attributes, listeners } =
        useSortable({ id: widget.id });

    const style = {
        transform: CSS.Translate.toString(transform),
        transition,
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={SIZE_CLASSES[widget.size]}
        >
            {children({ ...attributes, ...listeners })}
        </div>
    );
}

function RemoveWidgetDialog({
    widget,
    teamSlug,
    open,
    onOpenChange,
}: {
    widget: Widget | null;
    teamSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove widget?</DialogTitle>
                    <DialogDescription>
                        {widget && (
                            <>
                                This will permanently remove{' '}
                                <strong>"{widget.title}"</strong> from your
                                dashboard.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() => {
                            if (!widget) {
                                return;
                            }

                            router.delete(
                                destroy.url({
                                    current_team: teamSlug,
                                    widget: widget.id,
                                }),
                                { preserveScroll: true },
                            );
                            onOpenChange(false);
                        }}
                    >
                        Remove
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Dashboard({
    widgets,
    servers,
    sources,
    pendingInvitations = [],
}: Props) {
    const { currentTeam } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );
    const [range, setRange] = useState<TimeRange>('1h');
    const [autoRefresh, setAutoRefresh] = useState(false);
    const [orderedWidgets, setOrderedWidgets] = useState<Widget[]>(widgets);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingWidget, setEditingWidget] = useState<Widget | null>(null);
    const [removingWidget, setRemovingWidget] = useState<Widget | null>(null);

    useEffect(() => {
        // Defer so the sync effect doesn't setState synchronously.
        const timeout = setTimeout(() => setOrderedWidgets(widgets), 0);

        return () => clearTimeout(timeout);
    }, [widgets]);

    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;

        if (!over || active.id === over.id) {
            return;
        }

        const oldIndex = orderedWidgets.findIndex((w) => w.id === active.id);
        const newIndex = orderedWidgets.findIndex((w) => w.id === over.id);
        const newOrder = arrayMove(orderedWidgets, oldIndex, newIndex);
        setOrderedWidgets(newOrder);

        router.patch(
            reorder.url({ current_team: teamSlug }),
            { ids: newOrder.map((w) => w.id) },
            { preserveScroll: true },
        );
    };

    const handleResize = (widget: Widget, size: WidgetSize) => {
        router.patch(
            update.url({ current_team: teamSlug, widget: widget.id }),
            { title: widget.title, size, config: widget.config },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Dashboard" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <h1 className="flex-1 text-lg font-semibold">Dashboard</h1>
                    <Select
                        value={range}
                        onValueChange={(value) => setRange(value as TimeRange)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {RANGES.map((r) => (
                                <SelectItem key={r.value} value={r.value}>
                                    {r.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {autoRefresh && (
                        <span className="size-2 animate-pulse rounded-full bg-primary shadow-glow" />
                    )}
                    <Button
                        variant={autoRefresh ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setAutoRefresh(!autoRefresh)}
                    >
                        <RefreshCw className="size-4" />{' '}
                        {autoRefresh ? '30s' : 'Off'}
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => {
                            setEditingWidget(null);
                            setDialogOpen(true);
                        }}
                    >
                        <Plus className="size-4" /> Add widget
                    </Button>
                </div>

                {orderedWidgets.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center">
                        <LayoutDashboard className="size-8 text-muted-foreground" />
                        <p className="font-medium">Your dashboard is empty</p>
                        <Button
                            size="sm"
                            onClick={() => {
                                setEditingWidget(null);
                                setDialogOpen(true);
                            }}
                        >
                            <Plus className="size-4" /> Add widget
                        </Button>
                    </div>
                ) : (
                    <DndContext
                        collisionDetection={closestCenter}
                        onDragEnd={handleDragEnd}
                    >
                        <SortableContext
                            items={orderedWidgets.map((w) => w.id)}
                            strategy={rectSortingStrategy}
                        >
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-6">
                                {orderedWidgets.map((widget) => (
                                    <SortableWidget
                                        key={widget.id}
                                        widget={widget}
                                    >
                                        {(dragHandleProps) => (
                                            <WidgetFrame
                                                widget={widget}
                                                dragHandleProps={
                                                    dragHandleProps
                                                }
                                                onEdit={() => {
                                                    setEditingWidget(widget);
                                                    setDialogOpen(true);
                                                }}
                                                onResize={(size) =>
                                                    handleResize(widget, size)
                                                }
                                                onRemove={() =>
                                                    setRemovingWidget(widget)
                                                }
                                            >
                                                <WidgetBody
                                                    teamSlug={teamSlug}
                                                    widget={widget}
                                                    range={range}
                                                    refreshMs={
                                                        autoRefresh
                                                            ? 30_000
                                                            : null
                                                    }
                                                />
                                            </WidgetFrame>
                                        )}
                                    </SortableWidget>
                                ))}
                            </div>
                        </SortableContext>
                    </DndContext>
                )}
            </div>

            <WidgetDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                teamSlug={teamSlug}
                servers={servers}
                sources={sources}
                widget={editingWidget}
            />

            <RemoveWidgetDialog
                widget={removingWidget}
                teamSlug={teamSlug}
                open={removingWidget !== null}
                onOpenChange={(open) => !open && setRemovingWidget(null)}
            />
        </>
    );
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
