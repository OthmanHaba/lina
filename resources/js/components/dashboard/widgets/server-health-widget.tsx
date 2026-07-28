import { cn } from '@/lib/utils';
import type { ServerHealthData } from '@/types/dashboard';

// Server::status() (app/Models/Server.php) returns only 'pending' | 'online' | 'offline'.
// 'stale' is kept for forward-compat with the brief's spec but never occurs today.
const DOT: Record<string, string> = {
    online: 'bg-emerald-500',
    stale: 'bg-amber-500',
    offline: 'bg-red-500',
    pending: 'bg-muted-foreground/40',
};

export default function ServerHealthWidget({
    data,
}: {
    data: ServerHealthData;
}) {
    if (data.servers.length === 0) {
        return (
            <p className="flex h-full min-h-24 items-center justify-center text-sm text-muted-foreground">
                No servers connected.
            </p>
        );
    }

    return (
        <ul className="flex flex-col gap-2">
            {data.servers.map((server) => (
                <li key={server.id} className="flex items-center gap-2 text-sm">
                    <span
                        className={cn(
                            'size-2 shrink-0 rounded-full',
                            DOT[server.status] ?? 'bg-muted-foreground/40',
                        )}
                    />
                    <span className="min-w-0 flex-1 truncate">
                        {server.name}
                    </span>
                    <span className="text-xs text-muted-foreground capitalize">
                        {server.status}
                    </span>
                </li>
            ))}
        </ul>
    );
}
