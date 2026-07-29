import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dashboard, documentation, home, login } from '@/routes';

export default function PublicHeader() {
    const page = usePage();
    const { auth, currentTeam } = page.props;
    const dashboardRoute = currentTeam ? dashboard(currentTeam.slug) : home();
    const isDocumentation = page.component === 'docs';

    return (
        <header className="sticky top-0 z-50 border-b border-white/8 bg-[#0e1014]/88 backdrop-blur-xl">
            <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-6 px-4 sm:px-6 lg:px-8">
                <Link
                    href={home()}
                    className="shrink-0 text-xl font-bold tracking-[-0.04em] text-white"
                    aria-label="Lina home"
                >
                    lina<span className="text-primary">.</span>
                </Link>

                <nav
                    className="hidden items-center gap-7 text-sm text-[#9aa4b5] md:flex"
                    aria-label="Public navigation"
                >
                    <a
                        href={`${home().url}#capabilities`}
                        className="transition-colors hover:text-white"
                    >
                        Capabilities
                    </a>
                    <a
                        href={`${home().url}#architecture`}
                        className="transition-colors hover:text-white"
                    >
                        Architecture
                    </a>
                    <Link
                        href={documentation()}
                        className="transition-colors hover:text-white"
                    >
                        Documentation
                    </Link>
                    <a
                        href="https://github.com/OthmanHaba/lina"
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 transition-colors hover:text-white"
                    >
                        GitHub
                        <ArrowUpRight className="size-3.5" aria-hidden="true" />
                    </a>
                </nav>

                <div className="flex items-center gap-1.5">
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="inline-flex md:hidden"
                    >
                        <Link
                            href={
                                auth.user
                                    ? dashboardRoute
                                    : isDocumentation
                                      ? home()
                                      : documentation()
                            }
                        >
                            {auth.user
                                ? 'Dashboard'
                                : isDocumentation
                                  ? 'Home'
                                  : 'Docs'}
                        </Link>
                    </Button>
                    {!auth.user && (
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="hidden lg:inline-flex"
                        >
                            <Link href={login()}>Log in</Link>
                        </Button>
                    )}
                    {auth.user ? (
                        <Button asChild size="sm">
                            <Link href={dashboardRoute}>Open Lina</Link>
                        </Button>
                    ) : (
                        <Button asChild size="sm">
                            <a href={`${home().url}#install`}>Install Lina</a>
                        </Button>
                    )}
                </div>
            </div>
        </header>
    );
}
