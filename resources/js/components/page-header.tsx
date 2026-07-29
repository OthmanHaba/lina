import type { ReactNode } from 'react';

export default function PageHeader({
    title,
    description,
    action,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="text-sm text-foreground/70">{description}</p>
                )}
            </div>
            {action && (
                <div className="flex shrink-0 items-center gap-2">{action}</div>
            )}
        </div>
    );
}
