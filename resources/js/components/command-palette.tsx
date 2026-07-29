import { router, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    Bell,
    LayoutDashboard,
    ScrollText,
    Search,
    SearchX,
    Server,
    ShieldCheck,
    UserRound,
    Users,
} from 'lucide-react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as alertsIndex } from '@/routes/alerts';
import { index as logsIndex } from '@/routes/logs';
import { edit as profileEdit } from '@/routes/profile';
import { edit as securityEdit } from '@/routes/security';
import { index as serversIndex } from '@/routes/servers';
import { index as teamsIndex } from '@/routes/teams';

type CommandGroup = 'Workspace' | 'Account';

type CommandItem = {
    id: string;
    label: string;
    description: string;
    group: CommandGroup;
    href: string;
    icon: typeof Search;
    keywords: string[];
};

const groups: CommandGroup[] = ['Workspace', 'Account'];

export default function CommandPalette() {
    const { currentTeam } = usePage().props;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const itemRefs = useRef(new Map<string, HTMLButtonElement>());

    const commands = useMemo<CommandItem[]>(() => {
        const accountCommands: CommandItem[] = [
            {
                id: 'profile',
                label: 'Profile settings',
                description: 'Update your name and email',
                group: 'Account',
                href: profileEdit.url(),
                icon: UserRound,
                keywords: ['account', 'email', 'name', 'settings'],
            },
            {
                id: 'security',
                label: 'Security',
                description: 'Change your password and security options',
                group: 'Account',
                href: securityEdit.url(),
                icon: ShieldCheck,
                keywords: ['password', 'authentication', 'settings'],
            },
            {
                id: 'teams',
                label: 'Teams',
                description: 'Manage teams, members, and invitations',
                group: 'Account',
                href: teamsIndex.url(),
                icon: Users,
                keywords: ['members', 'invitations', 'workspace', 'settings'],
            },
        ];

        if (!currentTeam) {
            return accountCommands;
        }

        const teamSlug = currentTeam.slug;

        return [
            {
                id: 'dashboard',
                label: 'Dashboard',
                description: 'Open your monitoring overview',
                group: 'Workspace',
                href: dashboard.url(teamSlug),
                icon: LayoutDashboard,
                keywords: ['home', 'overview', 'widgets'],
            },
            {
                id: 'servers',
                label: 'Servers',
                description: 'View connected machines and log sources',
                group: 'Workspace',
                href: serversIndex.url(teamSlug),
                icon: Server,
                keywords: ['machines', 'hosts', 'agents', 'sources'],
            },
            {
                id: 'logs',
                label: 'Logs',
                description: 'Search and live-tail server logs',
                group: 'Workspace',
                href: logsIndex.url(teamSlug),
                icon: ScrollText,
                keywords: ['search', 'tail', 'events', 'errors'],
            },
            {
                id: 'alerts',
                label: 'Alerts',
                description: 'Manage rules and notification channels',
                group: 'Workspace',
                href: alertsIndex.url(teamSlug),
                icon: Bell,
                keywords: ['rules', 'notifications', 'channels'],
            },
            ...accountCommands,
        ];
    }, [currentTeam]);

    const filteredCommands = useMemo(() => {
        const terms = query.toLowerCase().trim().split(/\s+/).filter(Boolean);

        if (terms.length === 0) {
            return commands;
        }

        return commands.filter((command) => {
            const searchableText = [
                command.label,
                command.description,
                ...command.keywords,
            ]
                .join(' ')
                .toLowerCase();

            return terms.every((term) => searchableText.includes(term));
        });
    }, [commands, query]);

    const activeCommand = filteredCommands[activeIndex];

    useEffect(() => {
        const handleShortcut = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                setQuery('');
                setActiveIndex(0);
                setOpen((isOpen) => !isOpen);
            }
        };

        document.addEventListener('keydown', handleShortcut);

        return () => document.removeEventListener('keydown', handleShortcut);
    }, []);

    useEffect(() => {
        if (!activeCommand) {
            return;
        }

        itemRefs.current
            .get(activeCommand.id)
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeCommand]);

    const selectCommand = (command: CommandItem) => {
        setQuery('');
        setActiveIndex(0);
        setOpen(false);

        if (window.location.pathname !== command.href) {
            router.visit(command.href);
        }
    };

    const handleOpenChange = (nextOpen: boolean) => {
        setOpen(nextOpen);
        setQuery('');
        setActiveIndex(0);
    };

    const handleInputKeyDown = (
        event: ReactKeyboardEvent<HTMLInputElement>,
    ) => {
        if (filteredCommands.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((index) => (index + 1) % filteredCommands.length);
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex(
                (index) =>
                    (index - 1 + filteredCommands.length) %
                    filteredCommands.length,
            );
        }

        if (event.key === 'Enter' && activeCommand) {
            event.preventDefault();
            selectCommand(activeCommand);
        }
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                className="ml-auto size-11 justify-center bg-card px-0 text-foreground/70 shadow-none sm:h-8 sm:w-56 sm:justify-between sm:px-3"
                aria-label="Open command palette"
                aria-keyshortcuts="Control+K Meta+K"
                title="Open command palette (Ctrl+K or ⌘K)"
                onClick={() => handleOpenChange(true)}
            >
                <span className="flex items-center gap-2">
                    <Search className="size-4" />
                    <span className="hidden sm:inline">Search Lina</span>
                </span>
                <kbd className="hidden items-center gap-1 rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px] text-foreground/70 sm:inline-flex">
                    <span className="hidden lg:inline">Ctrl /</span> ⌘ K
                </kbd>
            </Button>

            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent
                    className="top-[12vh] flex max-h-[min(76vh,36rem)] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl"
                    data-test="command-palette"
                >
                    <DialogHeader className="sr-only">
                        <DialogTitle>Command palette</DialogTitle>
                        <DialogDescription>
                            Search Lina and navigate to a page. Use the arrow
                            keys to move and Enter to open.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex items-center gap-3 border-b border-border px-4 pr-14">
                        <Search
                            className="size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActiveIndex(0);
                            }}
                            onKeyDown={handleInputKeyDown}
                            className="h-14 min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-foreground/60"
                            placeholder="Search pages and settings…"
                            role="combobox"
                            aria-label="Search commands"
                            aria-autocomplete="list"
                            aria-controls="command-palette-results"
                            aria-expanded={open}
                            aria-activedescendant={
                                activeCommand
                                    ? `command-${activeCommand.id}`
                                    : undefined
                            }
                        />
                    </div>

                    <div
                        id="command-palette-results"
                        role="listbox"
                        aria-label="Commands"
                        className="min-h-0 flex-1 overflow-y-auto p-2"
                    >
                        {filteredCommands.length === 0 ? (
                            <div className="flex min-h-48 flex-col items-center justify-center gap-2 px-6 text-center">
                                <SearchX className="size-6 text-foreground/60" />
                                <p className="text-sm font-medium">
                                    No commands found
                                </p>
                                <p className="text-xs text-foreground/70">
                                    Try searching for logs, servers, or
                                    settings.
                                </p>
                            </div>
                        ) : (
                            groups.map((group) => {
                                const groupCommands = filteredCommands.filter(
                                    (command) => command.group === group,
                                );

                                if (groupCommands.length === 0) {
                                    return null;
                                }

                                return (
                                    <section
                                        key={group}
                                        aria-labelledby={`command-group-${group.toLowerCase()}`}
                                        className="not-first:mt-2"
                                    >
                                        <h3
                                            id={`command-group-${group.toLowerCase()}`}
                                            className="px-3 py-2 text-[11px] font-medium tracking-[0.12em] text-foreground/60 uppercase"
                                        >
                                            {group}
                                        </h3>
                                        <div className="space-y-1">
                                            {groupCommands.map((command) => {
                                                const index =
                                                    filteredCommands.indexOf(
                                                        command,
                                                    );
                                                const isActive =
                                                    index === activeIndex;
                                                const Icon = command.icon;

                                                return (
                                                    <button
                                                        key={command.id}
                                                        ref={(element) => {
                                                            if (element) {
                                                                itemRefs.current.set(
                                                                    command.id,
                                                                    element,
                                                                );
                                                            } else {
                                                                itemRefs.current.delete(
                                                                    command.id,
                                                                );
                                                            }
                                                        }}
                                                        id={`command-${command.id}`}
                                                        type="button"
                                                        role="option"
                                                        aria-selected={isActive}
                                                        className={cn(
                                                            'relative flex min-h-14 w-full items-center gap-3 rounded-md px-3 text-left transition-colors outline-none',
                                                            isActive
                                                                ? 'bg-accent text-accent-foreground'
                                                                : 'hover:bg-accent/60 focus-visible:bg-accent',
                                                        )}
                                                        onMouseMove={() =>
                                                            setActiveIndex(
                                                                index,
                                                            )
                                                        }
                                                        onClick={() =>
                                                            selectCommand(
                                                                command,
                                                            )
                                                        }
                                                    >
                                                        <span
                                                            className={cn(
                                                                'flex size-9 shrink-0 items-center justify-center rounded-md border',
                                                                isActive
                                                                    ? 'border-primary/40 bg-primary/10 text-primary'
                                                                    : 'border-border bg-card text-foreground/70',
                                                            )}
                                                        >
                                                            <Icon className="size-4" />
                                                        </span>
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block truncate text-sm font-medium">
                                                                {command.label}
                                                            </span>
                                                            <span className="block truncate text-xs text-foreground/70">
                                                                {
                                                                    command.description
                                                                }
                                                            </span>
                                                        </span>
                                                        <ArrowUpRight
                                                            className={cn(
                                                                'size-4 shrink-0 transition-opacity',
                                                                isActive
                                                                    ? 'opacity-100'
                                                                    : 'opacity-0',
                                                            )}
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </section>
                                );
                            })
                        )}
                    </div>

                    <div className="hidden items-center gap-4 border-t border-border px-4 py-2 text-[11px] text-foreground/60 sm:flex">
                        <span>
                            <kbd className="font-mono">↑↓</kbd> Navigate
                        </span>
                        <span>
                            <kbd className="font-mono">↵</kbd> Open
                        </span>
                        <span className="ml-auto">
                            <kbd className="font-mono">Esc</kbd> Close
                        </span>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
