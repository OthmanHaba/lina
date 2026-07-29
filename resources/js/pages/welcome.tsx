import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BellRing,
    Boxes,
    Check,
    Database,
    FileSearch,
    LayoutDashboard,
    Radio,
    Server,
    ShieldCheck,
    Terminal,
    Users,
} from 'lucide-react';
import CodeCommand from '@/components/marketing/code-command';
import PublicHeader from '@/components/marketing/public-header';
import { Button } from '@/components/ui/button';
import { dashboard, documentation, home, register } from '@/routes';

const installCommand =
    'curl -fsSL https://raw.githubusercontent.com/OthmanHaba/lina/main/install.sh | sudo bash -s -- --domain logs.example.com';

const capabilities = [
    {
        icon: FileSearch,
        title: 'Search every stream',
        description:
            'Query systemd, Docker, Nginx, container, and file logs from one focused explorer.',
        className: 'lg:col-span-7',
    },
    {
        icon: LayoutDashboard,
        title: 'Build the view you need',
        description:
            'Compose live log volume, stream, alert, stat, and server health widgets.',
        className: 'lg:col-span-5',
    },
    {
        icon: BellRing,
        title: 'Know when behavior changes',
        description:
            'Trigger threshold and missing-log alerts through email, webhooks, or Telegram.',
        className: 'lg:col-span-4',
    },
    {
        icon: ShieldCheck,
        title: 'Keep teams isolated',
        description:
            'Every query and ingest request is scoped to a team at the application and storage layers.',
        className: 'lg:col-span-8',
    },
];

const architecture = [
    {
        icon: Server,
        index: '01',
        title: 'Your servers',
        description:
            'A one-line command installs Grafana Alloy and fetches its managed configuration.',
    },
    {
        icon: Radio,
        index: '02',
        title: 'Lina gateway',
        description:
            'Token authentication assigns the team boundary before any log reaches storage.',
    },
    {
        icon: Database,
        index: '03',
        title: 'Private Loki',
        description:
            'Loki stores streams locally with team and server retention policies applied.',
    },
];

export default function Welcome() {
    const { auth, currentTeam } = usePage().props;
    const dashboardRoute = currentTeam ? dashboard(currentTeam.slug) : home();
    const primaryRoute = auth.user ? dashboardRoute : register();

    return (
        <>
            <Head title="Self-hosted log operations">
                <meta
                    name="description"
                    content="Lina is a self-hosted log operations platform for collecting, searching, visualizing, and alerting on Linux server logs."
                />
                <meta
                    property="og:title"
                    content="Lina | Self-hosted log operations"
                />
                <meta
                    property="og:description"
                    content="Own the complete path from Linux server logs to search, dashboards, and alerts."
                />
                <meta
                    property="og:image"
                    content="/images/lina-server-rack.webp"
                />
            </Head>

            <div className="min-h-[100dvh] overflow-hidden bg-[#0e1014] text-[#dde3ee]">
                <PublicHeader />

                <main>
                    <section className="relative isolate">
                        <div
                            className="pointer-events-none absolute inset-0 -z-10 opacity-35"
                            aria-hidden="true"
                            style={{
                                backgroundImage:
                                    'linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px)',
                                backgroundSize: '72px 72px',
                                maskImage:
                                    'linear-gradient(to bottom, black, transparent 88%)',
                            }}
                        />

                        <div className="mx-auto grid min-h-[calc(100dvh-4rem)] w-full max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8 lg:py-20">
                            <div className="marketing-reveal max-w-2xl">
                                <p className="mb-5 font-mono text-xs font-medium tracking-[0.2em] text-primary uppercase">
                                    Self-hosted log operations
                                </p>
                                <h1 className="text-5xl leading-[0.94] font-semibold tracking-[-0.055em] text-balance text-white sm:text-6xl lg:text-[5.15rem]">
                                    Your logs talk.
                                    <br />
                                    Lina listens.
                                </h1>
                                <p className="mt-7 max-w-xl text-lg leading-8 text-[#9aa4b5]">
                                    Collect, search, visualize, and alert on
                                    Linux logs from infrastructure you own.
                                </p>
                                <div className="mt-9 flex flex-wrap items-center gap-3">
                                    <Button
                                        asChild
                                        size="lg"
                                        className="min-w-36"
                                    >
                                        <Link href={primaryRoute}>
                                            {auth.user
                                                ? 'Open dashboard'
                                                : 'Start with Lina'}
                                            <ArrowRight />
                                        </Link>
                                    </Button>
                                    <Button asChild size="lg" variant="outline">
                                        <Link href={documentation()}>
                                            Read the docs
                                        </Link>
                                    </Button>
                                </div>
                            </div>

                            <div className="marketing-reveal marketing-delay-1 relative">
                                <div className="absolute -inset-8 -z-10 bg-primary/6 blur-3xl" />
                                <div className="relative overflow-hidden rounded-[0.9rem] border border-white/12 bg-[#111318] shadow-2xl shadow-black/50">
                                    <img
                                        src="/images/lina-server-rack.webp"
                                        alt="A private server rack in a dark data center with a chartreuse data path"
                                        width="1586"
                                        height="992"
                                        fetchPriority="high"
                                        className="aspect-[1.6/1] w-full object-cover"
                                    />
                                    <div className="absolute inset-x-0 bottom-0 flex items-end justify-between gap-5 bg-gradient-to-t from-[#090b0e] via-[#090b0e]/85 to-transparent px-5 pt-20 pb-5 sm:px-6">
                                        <div>
                                            <p className="font-mono text-xs text-primary">
                                                / infrastructure-owned
                                            </p>
                                            <p className="mt-1 text-sm text-white">
                                                No external log vendor required
                                            </p>
                                        </div>
                                        <div className="hidden items-center gap-2 font-mono text-xs text-[#9aa4b5] sm:flex">
                                            <span className="size-2 rounded-full bg-[#3dd68c] shadow-[0_0_8px_rgba(61,214,140,.55)]" />
                                            healthy
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="border-y border-white/8 bg-[#111318]">
                        <div className="mx-auto grid w-full max-w-7xl grid-cols-2 gap-px bg-white/8 px-4 sm:px-6 md:grid-cols-4 lg:px-8">
                            {[
                                'Linux native',
                                'Team isolated',
                                'Loki powered',
                                'Open source',
                            ].map((item) => (
                                <div
                                    key={item}
                                    className="flex min-h-20 items-center justify-center gap-2 bg-[#111318] px-4 text-center font-mono text-xs text-[#9aa4b5]"
                                >
                                    <Check className="size-3.5 text-primary" />
                                    {item}
                                </div>
                            ))}
                        </div>
                    </section>

                    <section
                        id="capabilities"
                        className="mx-auto w-full max-w-7xl px-4 py-24 sm:px-6 lg:px-8 lg:py-32"
                    >
                        <div className="max-w-3xl">
                            <h2 className="text-4xl leading-tight font-semibold tracking-[-0.04em] text-white sm:text-5xl">
                                One control surface for the full log path.
                            </h2>
                            <p className="mt-5 max-w-2xl text-lg leading-8 text-[#9aa4b5]">
                                Lina turns raw server streams into an
                                operational workflow without hiding the
                                infrastructure underneath.
                            </p>
                        </div>

                        <div className="mt-14 grid grid-cols-1 gap-4 lg:grid-cols-12">
                            {capabilities.map((capability, index) => (
                                <article
                                    key={capability.title}
                                    className={`group relative min-h-64 overflow-hidden rounded-lg border border-white/10 bg-[#161a22] p-7 transition-[border-color,transform] duration-300 hover:-translate-y-1 hover:border-primary/35 ${capability.className}`}
                                >
                                    <div
                                        className="absolute top-0 right-0 h-44 w-44 opacity-30"
                                        aria-hidden="true"
                                        style={{
                                            background:
                                                index % 2 === 0
                                                    ? 'radial-gradient(circle at top right, rgba(200,245,66,.25), transparent 68%)'
                                                    : 'linear-gradient(135deg, transparent 30%, rgba(94,234,212,.1))',
                                        }}
                                    />
                                    <capability.icon className="size-6 text-primary" />
                                    <h3 className="mt-16 text-2xl font-semibold text-white">
                                        {capability.title}
                                    </h3>
                                    <p className="mt-3 max-w-xl leading-7 text-[#9aa4b5]">
                                        {capability.description}
                                    </p>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section
                        id="architecture"
                        className="border-y border-white/8 bg-[#111318]"
                    >
                        <div className="mx-auto w-full max-w-7xl px-4 py-24 sm:px-6 lg:px-8 lg:py-32">
                            <div className="max-w-3xl">
                                <h2 className="text-4xl leading-tight font-semibold tracking-[-0.04em] text-white sm:text-5xl">
                                    A short, inspectable route from host to
                                    answer.
                                </h2>
                                <p className="mt-5 max-w-2xl text-lg leading-8 text-[#9aa4b5]">
                                    Collection stays lightweight. Tenancy is
                                    assigned before storage. Queries never leave
                                    your stack.
                                </p>
                            </div>

                            <div className="relative mt-16 grid gap-10 lg:grid-cols-3 lg:gap-8">
                                <div
                                    className="absolute top-10 right-[16%] left-[16%] hidden h-px bg-gradient-to-r from-primary/10 via-primary/60 to-primary/10 lg:block"
                                    aria-hidden="true"
                                />
                                {architecture.map((step) => (
                                    <article
                                        key={step.title}
                                        className="relative bg-[#111318]"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="grid size-20 place-items-center rounded-lg border border-primary/30 bg-primary/6 text-primary">
                                                <step.icon className="size-7" />
                                            </span>
                                            <span className="font-mono text-xs text-[#6b7484]">
                                                {step.index}
                                            </span>
                                        </div>
                                        <h3 className="mt-7 text-2xl font-semibold text-white">
                                            {step.title}
                                        </h3>
                                        <p className="mt-3 leading-7 text-[#9aa4b5]">
                                            {step.description}
                                        </p>
                                    </article>
                                ))}
                            </div>

                            <div className="mt-16 flex flex-wrap items-center gap-x-7 gap-y-3 border-t border-white/8 pt-7 font-mono text-xs text-[#6b7484]">
                                <span>Grafana Alloy</span>
                                <span aria-hidden="true">→</span>
                                <span>Laravel ingest gateway</span>
                                <span aria-hidden="true">→</span>
                                <span>Grafana Loki</span>
                                <span aria-hidden="true">→</span>
                                <span>Inertia dashboard</span>
                            </div>
                        </div>
                    </section>

                    <section
                        id="install"
                        className="mx-auto grid w-full max-w-7xl scroll-mt-20 gap-14 px-4 py-24 sm:px-6 lg:grid-cols-[0.75fr_1.25fr] lg:px-8 lg:py-32"
                    >
                        <div>
                            <Boxes className="size-7 text-primary" />
                            <h2 className="mt-7 text-4xl leading-tight font-semibold tracking-[-0.04em] text-white sm:text-5xl">
                                Install the whole system in one command.
                            </h2>
                            <p className="mt-5 text-lg leading-8 text-[#9aa4b5]">
                                A production stack with HTTPS, the app, Loki,
                                the scheduler, and a queue worker is built on
                                your host.
                            </p>
                            <Link
                                href={documentation()}
                                className="mt-7 inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
                            >
                                Review installation requirements
                                <ArrowRight className="size-4" />
                            </Link>
                        </div>

                        <div className="space-y-4">
                            <CodeCommand
                                command={installCommand}
                                label="Fresh Ubuntu, Debian, Fedora, or RHEL host"
                            />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="rounded-lg border border-white/8 bg-[#161a22] p-5">
                                    <Terminal className="size-5 text-primary" />
                                    <h3 className="mt-5 font-semibold text-white">
                                        Safe to run again
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-[#9aa4b5]">
                                        Existing secrets stay in place and
                                        SQLite is backed up before migrations.
                                    </p>
                                </div>
                                <div className="rounded-lg border border-white/8 bg-[#161a22] p-5">
                                    <Users className="size-5 text-primary" />
                                    <h3 className="mt-5 font-semibold text-white">
                                        Ready for a team
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-[#9aa4b5]">
                                        Register the first owner, then invite
                                        admins and members from Lina.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="px-4 pb-24 sm:px-6 lg:px-8 lg:pb-32">
                        <div className="mx-auto flex w-full max-w-7xl flex-col items-start justify-between gap-8 rounded-xl border border-primary/25 bg-[linear-gradient(120deg,rgba(200,245,66,.12),rgba(22,26,34,.75)_55%)] p-8 sm:p-12 lg:flex-row lg:items-end">
                            <div className="max-w-3xl">
                                <h2 className="text-4xl leading-tight font-semibold tracking-[-0.04em] text-white sm:text-5xl">
                                    Own your logs before you need them.
                                </h2>
                                <p className="mt-4 text-lg text-[#9aa4b5]">
                                    Deploy Lina, connect one Linux server, and
                                    trace the complete path in minutes.
                                </p>
                            </div>
                            <Button asChild size="lg" className="shrink-0">
                                <a
                                    href="https://github.com/OthmanHaba/lina"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    View source
                                    <ArrowRight />
                                </a>
                            </Button>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-white/8">
                    <div className="mx-auto flex w-full max-w-7xl flex-col gap-5 px-4 py-8 text-sm text-[#6b7484] sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                        <span className="text-base font-bold tracking-[-0.04em] text-white">
                            lina<span className="text-primary">.</span>
                        </span>
                        <p>Self-hosted log operations for Linux teams.</p>
                        <div className="flex items-center gap-5">
                            <Link
                                href={documentation()}
                                className="hover:text-white"
                            >
                                Documentation
                            </Link>
                            <a
                                href="https://github.com/OthmanHaba/lina"
                                className="hover:text-white"
                            >
                                Source
                            </a>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
