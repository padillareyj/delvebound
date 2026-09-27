import { Link, router, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import {
    Backpack,
    Bot,
    Castle,
    Crown,
    Hourglass,
    LogOut,
    Mail,
    Map as MapIcon,
    Shield,
    Store,
    Swords,
    UserRound,
} from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import Toasts from '@/components/Toasts';
import { Bar, Gold } from '@/components/ui';
import { INJURY_LABELS } from '@/lib/format';
import type { SharedProps } from '@/types';

const NAV = [
    { href: '/', label: 'Camp', icon: Castle, match: (u: string) => u === '/' },
    { href: '/character', label: 'Hero', icon: UserRound },
    { href: '/pack', label: 'Pack', icon: Backpack },
    { href: '/market', label: 'Market', icon: Store },
    { href: '/map', label: 'Map', icon: MapIcon },
    { href: '/mail', label: 'Mail', icon: Mail, match: (u: string) => u.startsWith('/mail') || u.startsWith('/trips') },
    { href: '/leaderboard', label: 'Ranks', icon: Crown },
    { href: '/bots', label: 'Rivals', icon: Bot },
    { href: '/arena', label: 'Arena', icon: Swords, match: (u: string) => u.startsWith('/arena') },
];

function isActive(url: string, item: (typeof NAV)[number]) {
    const path = url.split('?')[0];
    return item.match ? item.match(path) : path.startsWith(item.href);
}

function RunBanner() {
    const { run } = usePage<SharedProps>().props;

    useEffect(() => {
        if (!run?.running) return;
        const timer = window.setInterval(() => router.reload({ only: ['run', 'hero'] }), 5000);
        return () => clearInterval(timer);
    }, [run?.running]);

    useEffect(() => {
        if (run && !run.running && sessionStorage.getItem('delve:was-running') === '1') {
            sessionStorage.removeItem('delve:was-running');
            router.reload();
        }
        if (run?.running) sessionStorage.setItem('delve:was-running', '1');
    }, [run?.running]);

    if (!run?.running) return null;
    const last = run.last;
    return (
        <div className="border-b border-lantern-500/30 bg-gradient-to-r from-lantern-600/20 via-lantern-500/10 to-transparent">
            <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2 text-sm text-lantern-200">
                <Hourglass className="size-4 animate-spin [animation-duration:3s]" />
                <span className="font-semibold">{run.kind === 'arena' ? 'The arena is underway.' : 'The expedition is underway.'}</span>
                <span className="text-lantern-200/70">Changes are paused until everyone is back.</span>
                {last && last.characters_total > 0 && (
                    <span className="ml-auto flex items-center gap-2 tabular-nums">
                        <Bar value={last.characters_done} max={last.characters_total} className="w-28" />
                        {last.characters_done}/{last.characters_total}
                    </span>
                )}
            </div>
        </div>
    );
}

function HeroBar() {
    const { hero, auth } = usePage<SharedProps>().props;
    if (!hero) return null;
    const xpMax = hero.xp_to_next ?? hero.xp;
    return (
        <div className="flex min-w-0 flex-1 items-center justify-end gap-3 sm:gap-5">
            <Link href="/character" className="group flex shrink-0 items-center gap-3" title={`${hero.name}, level ${hero.level}`}>
                <div className="relative grid size-10 shrink-0 place-items-center rounded-full border border-lantern-400/50 bg-ink-800 font-display text-lg font-extrabold text-lantern-300">
                    {hero.level}
                    {hero.unspent > 0 && (
                        <span className="absolute -top-1 -right-1 grid size-5 place-items-center rounded-full bg-lantern-400 text-[10px] font-bold text-ink-950">
                            <span className="absolute inset-0 animate-pulse-ring rounded-full bg-lantern-400" />
                            <span className="relative">{hero.unspent}</span>
                        </span>
                    )}
                </div>
                <div className="hidden w-40 md:block xl:hidden 2xl:block">
                    <div className="flex items-baseline justify-between gap-2">
                        <span className="truncate font-semibold text-ink-100 group-hover:text-lantern-200">{hero.name}</span>
                        <span className="shrink-0 text-[11px] text-ink-400 tabular-nums">
                            {hero.xp_to_next ? `${hero.xp}/${hero.xp_to_next}` : 'Max'}
                        </span>
                    </div>
                    <Bar value={hero.xp} max={xpMax} tone="arcane" className="mt-1 h-1.5" />
                </div>
            </Link>
            {hero.injury_severity > 0 && (
                <Link href="/pack" className="chip border-blood-500/50 bg-blood-500/10 text-blood-400" title="Injury">
                    🩸 {INJURY_LABELS[hero.injury_severity]} · {hero.injury_runs_left} runs
                </Link>
            )}
            <Link href="/market" className="rounded-lg border border-ink-700 bg-ink-900/60 px-3 py-1.5 hover:border-lantern-500/40">
                <Gold amount={hero.gold} />
            </Link>
            <Link
                href="/mail"
                className="relative rounded-lg border border-ink-700 bg-ink-900/60 p-2 text-ink-200 hover:border-lantern-500/40 hover:text-lantern-200"
                title="Mail"
            >
                <Mail className="size-4" />
                {hero.unread > 0 && (
                    <span className="absolute -top-1.5 -right-1.5 grid min-w-5 place-items-center rounded-full bg-blood-500 px-1 text-[10px] font-bold text-white">
                        {hero.unread}
                    </span>
                )}
            </Link>
            <div className="flex items-center">
                <Link href="/account" className="rounded-lg p-2 text-ink-400 hover:text-ink-100" title={`Account (${auth.account?.username})`}>
                    <UserRound className="size-4" />
                </Link>
                <Link href="/logout" method="post" as="button" className="rounded-lg p-2 text-ink-400 hover:text-blood-400" title="Log out">
                    <LogOut className="size-4" />
                </Link>
            </div>
        </div>
    );
}

export default function GameLayout({ children }: { children: ReactNode }) {
    const { url, props } = usePage<SharedProps>();
    const isAdmin = props.auth.account?.is_admin;
    const nav = isAdmin
        ? [...NAV, { href: '/admin', label: 'Admin', icon: Shield, match: (u: string) => u.startsWith('/admin') || u.startsWith('/runs') }]
        : NAV;

    return (
        <div className="flex min-h-full flex-col">
            <header className="sticky top-0 z-40 border-b border-ink-700/60 bg-ink-900/85 backdrop-blur-lg">
                <div className="mx-auto flex max-w-7xl items-center gap-4 px-4 py-2.5">
                    <Link href="/" className="flex shrink-0 items-center gap-2.5">
                        <img src="/favicon.svg" alt="" className="size-8 animate-flicker" />
                        <span className="hidden font-display text-xl font-extrabold tracking-wider text-lantern-200 text-shadow-glow sm:block">
                            Delvebound
                        </span>
                    </Link>
                    <nav className="ml-4 hidden items-center gap-0.5 xl:flex">
                        {nav.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={clsx(
                                    'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium transition',
                                    isActive(url, item)
                                        ? 'bg-lantern-400/10 text-lantern-200 shadow-[inset_0_-2px_0_var(--color-lantern-400)]'
                                        : 'text-ink-300 hover:bg-ink-800 hover:text-ink-100',
                                )}
                            >
                                <item.icon className="size-4" />
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                    <HeroBar />
                </div>
                <RunBanner />
            </header>

            <main className="mx-auto w-full max-w-7xl flex-1 px-4 pt-6 pb-28 xl:pb-12">{children}</main>

            <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-ink-700/70 bg-ink-900/95 backdrop-blur-lg xl:hidden">
                <div className="mx-auto flex max-w-2xl overflow-x-auto">
                    {NAV.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={clsx(
                                'flex w-14 shrink-0 flex-col items-center gap-0.5 py-2 text-[10px] font-medium',
                                isActive(url, item) ? 'text-lantern-300' : 'text-ink-400',
                            )}
                        >
                            <item.icon className="size-5" />
                            {item.label}
                        </Link>
                    ))}
                </div>
            </nav>

            <footer className="hidden border-t border-ink-800 py-4 text-center text-xs text-ink-500 xl:block">
                <Swords className="mr-1 inline size-3" /> Delvebound · expeditions depart when the keeper of Lanternford rings the bell
            </footer>
            <Toasts />
        </div>
    );
}
