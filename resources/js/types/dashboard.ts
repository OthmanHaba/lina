export type WidgetType =
    | 'timeseries'
    | 'stat'
    | 'log_stream'
    | 'alert_status'
    | 'server_health';
export type WidgetSize = 'third' | 'half' | 'full';
export type TimeRange = '15m' | '1h' | '6h' | '24h' | '7d';

export type WidgetConfig = {
    server_ids?: number[];
    sources?: string[];
    search?: string | null;
    chart?: 'line' | 'bar' | 'area';
    group_by?: 'server' | 'source' | null;
    window_minutes?: number;
    limit?: number;
    logql?: string | null;
};

export type Widget = {
    id: number;
    type: WidgetType;
    title: string;
    size: WidgetSize;
    position: number;
    config: WidgetConfig;
};

export type SeriesPoint = [number, number];
export type TimeseriesData = {
    series: { label: string; points: SeriesPoint[] }[];
};
export type StatData = { value: number; previous: number };
export type LogStreamData = {
    entries: {
        ts: string;
        time: string;
        line: string;
        labels: Record<string, string>;
    }[];
};
export type AlertStatusData = {
    alerts: {
        id: number;
        name: string;
        state: string;
        enabled: boolean;
        changedAt: string | null;
    }[];
};
export type ServerHealthData = {
    servers: {
        id: number;
        name: string;
        status: string;
        configStatus: string;
    }[];
};
export type WidgetData = (
    | TimeseriesData
    | StatData
    | LogStreamData
    | AlertStatusData
    | ServerHealthData
) & { error?: string };
