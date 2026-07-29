import { Badge } from '@/components/ui/badge';
import { detectLevel } from '@/lib/log-level';
import type { LogStreamData } from '@/types/dashboard';

export default function LogStreamWidget({ data }: { data: LogStreamData }) {
    if (data.entries.length === 0) {
        return (
            <p className="flex h-full min-h-24 items-center justify-center text-sm text-foreground/70">
                No logs yet.
            </p>
        );
    }

    return (
        <div className="max-h-72 divide-y divide-border/50 overflow-y-auto font-mono text-xs">
            {data.entries.map((entry) => {
                const level = detectLevel(entry.line);

                return (
                    <div
                        key={entry.ts}
                        className="flex items-baseline gap-2 py-1 hover:bg-muted/50"
                    >
                        <span className="shrink-0 text-foreground/70">
                            {new Date(entry.time).toLocaleTimeString()}
                        </span>
                        {level && (
                            <Badge
                                variant={
                                    level === 'error'
                                        ? 'destructive'
                                        : 'warning'
                                }
                                className="h-4 px-1 text-[10px] uppercase"
                            >
                                {level}
                            </Badge>
                        )}
                        <span className="min-w-0 truncate">{entry.line}</span>
                    </div>
                );
            })}
        </div>
    );
}
