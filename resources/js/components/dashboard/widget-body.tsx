import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useWidgetData } from '@/hooks/use-widget-data';
import type { TimeRange, Widget } from '@/types/dashboard';

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

    return <pre className="text-xs">{JSON.stringify(data)}</pre>;
}
