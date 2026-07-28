import { Head, usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import WidgetBody from '@/components/dashboard/widget-body';
import WidgetFrame from '@/components/dashboard/widget-frame';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import type { DashboardInvitation } from '@/types';
import type { TimeRange, Widget } from '@/types/dashboard';

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

export default function Dashboard({ widgets, pendingInvitations = [] }: Props) {
    const { currentTeam } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );
    const [range, setRange] = useState<TimeRange>('1h');
    const [autoRefresh, setAutoRefresh] = useState(false);

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
                    <Button
                        variant={autoRefresh ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setAutoRefresh(!autoRefresh)}
                    >
                        <RefreshCw className="size-4" />{' '}
                        {autoRefresh ? '30s' : 'Off'}
                    </Button>
                    {/* Add widget button wired in Task 6 */}
                </div>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-6">
                    {widgets.map((widget) => (
                        <div
                            key={widget.id}
                            className={SIZE_CLASSES[widget.size]}
                        >
                            <WidgetFrame widget={widget}>
                                <WidgetBody
                                    teamSlug={teamSlug}
                                    widget={widget}
                                    range={range}
                                    refreshMs={autoRefresh ? 30_000 : null}
                                />
                            </WidgetFrame>
                        </div>
                    ))}
                </div>
            </div>
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
