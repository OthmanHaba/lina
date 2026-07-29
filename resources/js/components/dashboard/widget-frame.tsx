import { GripVertical, MoreVertical } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Widget, WidgetSize } from '@/types/dashboard';

type Props = {
    widget: Widget;
    children: ReactNode;
    onEdit?: () => void;
    onRemove?: () => void;
    onResize?: (size: WidgetSize) => void;
    dragHandleProps?: Record<string, unknown>;
};

export default function WidgetFrame({
    widget,
    children,
    onEdit,
    onRemove,
    onResize,
    dragHandleProps,
}: Props) {
    return (
        <Card className="flex h-full flex-col gap-2 py-4">
            <CardHeader className="flex flex-row items-center gap-1 px-4">
                <button
                    type="button"
                    className="-m-2 flex size-11 cursor-grab items-center justify-center text-foreground/60 hover:text-foreground/70 sm:-m-1 sm:size-8"
                    aria-label="Move widget"
                    {...dragHandleProps}
                >
                    <GripVertical className="size-4" />
                </button>
                <CardTitle className="flex-1 truncate text-sm font-medium">
                    {widget.title}
                </CardTitle>
                {(onEdit || onRemove || onResize) && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-11 sm:size-8"
                                aria-label="Widget options"
                            >
                                <MoreVertical className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {onEdit && (
                                <DropdownMenuItem onSelect={onEdit}>
                                    Edit
                                </DropdownMenuItem>
                            )}
                            {onResize && (
                                <DropdownMenuSub>
                                    <DropdownMenuSubTrigger>
                                        Size
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent>
                                        {(
                                            ['third', 'half', 'full'] as const
                                        ).map((size) => (
                                            <DropdownMenuItem
                                                key={size}
                                                onSelect={() => onResize(size)}
                                                disabled={size === widget.size}
                                            >
                                                {size === 'third'
                                                    ? 'Small (1/3)'
                                                    : size === 'half'
                                                      ? 'Medium (1/2)'
                                                      : 'Full width'}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                            )}
                            {onRemove && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={onRemove}
                                    >
                                        Remove
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </CardHeader>
            <CardContent className="min-h-0 flex-1 px-4">
                {children}
            </CardContent>
        </Card>
    );
}
