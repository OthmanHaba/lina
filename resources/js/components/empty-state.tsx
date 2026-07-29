import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export default function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed py-16 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                <Icon className="size-6 text-foreground/70" />
            </div>
            <div className="space-y-1">
                <p className="text-sm font-medium">{title}</p>
                {description && (
                    <p className="max-w-sm text-sm text-foreground/70">
                        {description}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}
