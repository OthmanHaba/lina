import { useCallback, useEffect, useState } from 'react';
import { data as widgetData } from '@/routes/dashboard/widgets';
import type { TimeRange, Widget, WidgetData } from '@/types/dashboard';

export function useWidgetData(
    teamSlug: string,
    widget: Widget,
    range: TimeRange,
    refreshMs: number | null,
) {
    const [data, setData] = useState<WidgetData | null>(null);
    const [loading, setLoading] = useState(true);

    const reload = useCallback(async () => {
        setLoading(true);

        try {
            const url = widgetData.url(
                { current_team: teamSlug, widget: widget.id },
                { query: { range } },
            );
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                setData({ error: 'Could not load this widget.' } as WidgetData);

                return;
            }

            setData((await response.json()) as WidgetData);
        } catch {
            setData({ error: 'Could not load this widget.' } as WidgetData);
        } finally {
            setLoading(false);
        }
    }, [teamSlug, widget.id, range]);

    useEffect(() => {
        // Defer so the effect doesn't call setState synchronously on mount.
        const timeout = setTimeout(() => void reload(), 0);

        return () => clearTimeout(timeout);
    }, [reload]);

    useEffect(() => {
        if (refreshMs === null) {
            return;
        }

        const interval = setInterval(() => void reload(), refreshMs);

        return () => clearInterval(interval);
    }, [refreshMs, reload]);

    return { data, loading, error: data?.error ?? null, reload };
}
