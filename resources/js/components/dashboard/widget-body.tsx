import AlertStatusWidget from '@/components/dashboard/widgets/alert-status-widget';
import LogStreamWidget from '@/components/dashboard/widgets/log-stream-widget';
import ServerHealthWidget from '@/components/dashboard/widgets/server-health-widget';
import StatWidget from '@/components/dashboard/widgets/stat-widget';
import TimeseriesWidget from '@/components/dashboard/widgets/timeseries-widget';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useWidgetData } from '@/hooks/use-widget-data';
import type {
    AlertStatusData,
    LogStreamData,
    ServerHealthData,
    StatData,
    TimeRange,
    TimeseriesData,
    Widget,
} from '@/types/dashboard';

type Props = {
    teamSlug: string;
    widget: Widget;
    range: TimeRange;
    refreshMs: number | null;
};

export default function WidgetBody({
    teamSlug,
    widget,
    range,
    refreshMs,
}: Props) {
    const { data, loading, error, reload } = useWidgetData(
        teamSlug,
        widget,
        range,
        refreshMs,
    );

    if (loading && !data) {
        return <Skeleton className="h-full min-h-24 w-full" />;
    }

    if (error) {
        return (
            <div className="flex h-full min-h-24 flex-col items-start justify-center gap-2 rounded-md border border-destructive/40 p-3 text-sm text-destructive">
                <span>{error}</span>
                <Button variant="outline" size="sm" onClick={() => reload()}>
                    Retry
                </Button>
            </div>
        );
    }

    switch (widget.type) {
        case 'timeseries':
            return (
                <TimeseriesWidget
                    widget={widget}
                    data={data as TimeseriesData}
                    range={range}
                />
            );
        case 'stat':
            return <StatWidget widget={widget} data={data as StatData} />;
        case 'log_stream':
            return <LogStreamWidget data={data as LogStreamData} />;
        case 'alert_status':
            return <AlertStatusWidget data={data as AlertStatusData} />;
        case 'server_health':
            return <ServerHealthWidget data={data as ServerHealthData} />;
        default:
            return null;
    }
}
