import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { CHART_COLORS } from '@/lib/chart';
import type { TimeRange, TimeseriesData, Widget } from '@/types/dashboard';

function formatTick(ts: number, range: TimeRange): string {
    const date = new Date(ts * 1000);

    return range === '7d' || range === '24h'
        ? date.toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : date.toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
          });
}

export default function TimeseriesWidget({
    widget,
    data,
    range,
}: {
    widget: Widget;
    data: TimeseriesData;
    range: TimeRange;
}) {
    if (
        data.series.length === 0 ||
        data.series.every((s) => s.points.length === 0)
    ) {
        return (
            <p className="flex h-full min-h-24 items-center justify-center text-sm text-muted-foreground">
                No logs in this range.
            </p>
        );
    }

    const labelCounts = new Map<string, number>();
    const uniqueLabels = data.series.map((series) => {
        const count = (labelCounts.get(series.label) ?? 0) + 1;
        labelCounts.set(series.label, count);

        return count === 1 ? series.label : `${series.label} (${count})`;
    });

    const rows = new Map<number, Record<string, number>>();

    data.series.forEach((series, index) => {
        const label = uniqueLabels[index];

        for (const [ts, value] of series.points) {
            const row = rows.get(ts) ?? {};
            row[label] = value;
            rows.set(ts, row);
        }
    });

    const chartData = [...rows.entries()]
        .sort(([a], [b]) => a - b)
        .map(([ts, values]) => ({ ts, ...values }));

    const kind = widget.config.chart ?? 'bar';
    const Chart =
        kind === 'line' ? LineChart : kind === 'area' ? AreaChart : BarChart;

    return (
        <ResponsiveContainer width="100%" height={220}>
            <Chart data={chartData}>
                <CartesianGrid
                    strokeDasharray="3 3"
                    className="stroke-border"
                    vertical={false}
                />
                <XAxis
                    dataKey="ts"
                    tickFormatter={(ts: number) => formatTick(ts, range)}
                    fontSize={11}
                    tickLine={false}
                    axisLine={false}
                    minTickGap={40}
                />
                <YAxis
                    fontSize={11}
                    tickLine={false}
                    axisLine={false}
                    width={40}
                    allowDecimals={false}
                />
                <Tooltip
                    labelFormatter={(ts) => formatTick(ts as number, range)}
                    contentStyle={{
                        fontSize: 12,
                        background: '#1d222c',
                        border: '1px solid #232833',
                        borderRadius: 9,
                        color: '#dde3ee',
                    }}
                />
                {data.series.map((series, index) => {
                    const color = CHART_COLORS[index % CHART_COLORS.length];
                    const label = uniqueLabels[index];

                    if (kind === 'line') {
                        return (
                            <Line
                                key={label}
                                type="monotone"
                                dataKey={label}
                                stroke={color}
                                dot={false}
                                strokeWidth={2}
                            />
                        );
                    }

                    if (kind === 'area') {
                        return (
                            <Area
                                key={label}
                                type="monotone"
                                dataKey={label}
                                stroke={color}
                                fill={color}
                                fillOpacity={0.2}
                                stackId="a"
                            />
                        );
                    }

                    return (
                        <Bar
                            key={label}
                            dataKey={label}
                            fill={color}
                            stackId="a"
                            radius={[2, 2, 0, 0]}
                        />
                    );
                })}
            </Chart>
        </ResponsiveContainer>
    );
}
