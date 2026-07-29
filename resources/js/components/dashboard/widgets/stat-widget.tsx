import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import type { StatData, Widget } from '@/types/dashboard';

const formatter = new Intl.NumberFormat(undefined, {
    notation: 'compact',
    maximumFractionDigits: 1,
});

export default function StatWidget({
    widget,
    data,
}: {
    widget: Widget;
    data: StatData;
}) {
    const delta =
        data.previous === 0
            ? null
            : ((data.value - data.previous) / data.previous) * 100;
    const window = widget.config.window_minutes ?? 60;

    return (
        <div className="flex h-full min-h-24 flex-col justify-center gap-1">
            <span className="font-mono text-3xl font-semibold tabular-nums">
                {formatter.format(data.value)}
            </span>
            <span className="flex items-center gap-1 text-xs text-foreground/70">
                {delta === null ? (
                    <Minus className="size-3" />
                ) : delta >= 0 ? (
                    <ArrowUpRight className="size-3 text-red-500" />
                ) : (
                    <ArrowDownRight className="size-3 text-emerald-500" />
                )}
                {delta === null
                    ? 'No previous data'
                    : `${Math.abs(delta).toFixed(0)}% vs previous ${window} min`}
            </span>
        </div>
    );
}
