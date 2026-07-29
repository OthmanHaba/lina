import { Check, Copy } from 'lucide-react';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

export default function CodeCommand({
    command,
    label = 'Terminal',
    className,
}: {
    command: string;
    label?: string;
    className?: string;
}) {
    const [copiedText, copy] = useClipboard();
    const isCopied = copiedText === command;

    return (
        <div
            className={cn(
                'overflow-hidden rounded-lg border border-white/10 bg-[#090b0e]',
                className,
            )}
        >
            <div className="flex h-10 items-center justify-between border-b border-white/8 px-3">
                <span className="font-mono text-[11px] tracking-[0.12em] text-[#6b7484] uppercase">
                    {label}
                </span>
                <button
                    type="button"
                    onClick={() => void copy(command)}
                    className="inline-flex min-h-9 items-center gap-1.5 rounded-md px-2 font-mono text-xs text-[#9aa4b5] transition-colors hover:bg-white/6 hover:text-white focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    aria-label="Copy command"
                >
                    {isCopied ? (
                        <>
                            <Check className="size-3.5 text-primary" />
                            Copied
                        </>
                    ) : (
                        <>
                            <Copy className="size-3.5" />
                            Copy
                        </>
                    )}
                </button>
            </div>
            <pre className="overflow-x-auto p-4 text-left font-mono text-[13px] leading-6 text-[#dde3ee]">
                <code>
                    <span className="text-primary select-none">$ </span>
                    {command}
                </code>
            </pre>
        </div>
    );
}
