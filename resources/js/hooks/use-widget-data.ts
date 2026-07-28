import { useCallback, useEffect, useRef, useState } from 'react';
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
    // Guards against a stale in-flight response (e.g. from a previous range)
    // overwriting a newer one: only the latest generation may set state.
    const generation = useRef(0);

    const reload = useCallback(async () => {
        const gen = ++generation.current;

        setLoading(true);

        try {
            const url = widgetData.url(
                { current_team: teamSlug, widget: widget.id },
                { query: { range } },
            );
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
            });

            if (gen !== generation.current) {
                return;
            }

            if (!response.ok) {
                setData({ error: 'Could not load this widget.' } as WidgetData);

                return;
            }

            const payload = (await response.json()) as WidgetData;

            if (gen === generation.current) {
                setData(payload);
            }
        } catch {
            if (gen === generation.current) {
                setData({ error: 'Could not load this widget.' } as WidgetData);
            }
        } finally {
            if (gen === generation.current) {
                setLoading(false);
            }
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
