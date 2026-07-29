import { Badge } from '@/components/ui/badge';
import type { AlertStatusData } from '@/types/dashboard';

export default function AlertStatusWidget({ data }: { data: AlertStatusData }) {
    if (data.alerts.length === 0) {
        return (
            <p className="flex h-full min-h-24 items-center justify-center text-sm text-foreground/70">
                No alerts configured.
            </p>
        );
    }

    return (
        <ul className="flex flex-col gap-2">
            {data.alerts.map((alert) => (
                <li key={alert.id} className="flex items-center gap-2 text-sm">
                    <span className="min-w-0 flex-1 truncate">
                        {alert.name}
                    </span>
                    {!alert.enabled ? (
                        <Badge variant="outline">Disabled</Badge>
                    ) : alert.state === 'firing' ? (
                        <Badge variant="destructive">Firing</Badge>
                    ) : (
                        <Badge variant="secondary">OK</Badge>
                    )}
                </li>
            ))}
        </ul>
    );
}
