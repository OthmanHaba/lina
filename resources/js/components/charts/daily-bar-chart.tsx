import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { CHART_COLORS } from '@/lib/chart';

type Props = {
    data: { label: string; value: number }[];
    height?: number;
    formatValue?: (value: number) => string;
};

export default function DailyBarChart({
    data,
    height = 160,
    formatValue = String,
}: Props) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={data}>
                <CartesianGrid
                    strokeDasharray="3 3"
                    className="stroke-border"
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    fontSize={10}
                    tickLine={false}
                    axisLine={false}
                    minTickGap={20}
                />
                <YAxis
                    fontSize={10}
                    tickLine={false}
                    axisLine={false}
                    width={44}
                    tickFormatter={formatValue}
                />
                <Tooltip
                    formatter={(value) => formatValue(value as number)}
                    contentStyle={{
                        fontSize: 12,
                        background: '#1d222c',
                        border: '1px solid #232833',
                        borderRadius: 9,
                        color: '#dde3ee',
                    }}
                />
                <Bar
                    dataKey="value"
                    fill={CHART_COLORS[0]}
                    radius={[3, 3, 0, 0]}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}
