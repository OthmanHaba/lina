import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

export default function Welcome() {
    const { auth, currentTeam } = usePage().props;
    const dashboardUrl = currentTeam ? dashboard(currentTeam.slug) : '/';

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between p-6 lg:p-8">
                    <span className="text-lg font-bold tracking-tight text-white">
                        lina<span className="text-primary">.</span>
                    </span>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild size="sm">
                                <Link href={dashboardUrl}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild size="sm">
                                    <Link href={register()}>Register</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-16 lg:px-8">
                    <p className="mb-4 text-sm font-medium tracking-widest text-primary uppercase">
                        Log management, done simply
                    </p>
                    <h1 className="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                        Ship your logs. Watch them. Get alerted.
                    </h1>
                    <p className="mt-6 max-w-2xl text-lg text-muted-foreground">
                        Connect a Linux server in one command, build dashboards
                        over your logs, and get notified the moment something
                        goes quiet or noisy.
                    </p>
                    <div className="mt-10 flex flex-wrap items-center gap-3">
                        {auth.user ? (
                            <Button asChild size="lg">
                                <Link href={dashboardUrl}>
                                    Go to dashboard
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild size="lg">
                                    <Link href={register()}>Get started</Link>
                                </Button>
                                <Button asChild variant="outline" size="lg">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            </>
                        )}
                    </div>

                    <div className="mt-16 overflow-x-auto rounded-xl border border-border bg-card p-4">
                        <code className="font-mono text-sm text-muted-foreground">
                            <span className="text-primary">$</span> curl -sSL
                            lina.test/install/&lt;token&gt; | sudo bash
                        </code>
                    </div>
                </main>
            </div>
        </>
    );
}
