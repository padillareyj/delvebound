import { Link, router, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { ChevronDown, Hourglass, LogOut, Mail, Shield, Swords, UserRound } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import GameDialog from '@/components/GameDialog';
import { TitleBadge } from '@/components/Premium';
import Toasts from '@/components/Toasts';
import { Bar, Gold } from '@/components/ui';
import { INJURY_LABELS, money } from '@/lib/format';
import { NAV, isNavActive, type NavEntry } from '@/lib/nav';
import type { SharedProps } from '@/types';

const ADMIN_NAV: NavEntry = {
    href: '/admin',
    label: 'Admin',
    icon: Shield,
    match: (path) => path.startsWith('/admin') || path.startsWith('/runs'),
};

function DesktopNav({ items, url }: { items: NavEntry[]; url: string }) {
    const [open, setOpen] = useState<string | null>(null);
    const closeTimer = useRef<number | null>(null);

    useEffect(() => {
        setOpen(null);
    }, [url]);

    useEffect(() => {
        if (!open) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(null);
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open]);

    const clearClose = () => {
        if (closeTimer.current !== null) window.clearTimeout(closeTimer.current);
    };

    const show = (href: string) => {
        clearClose();
        setOpen(href);
    };

    const hideSoon = () => {
        clearClose();
        closeTimer.current = window.setTimeout(() => setOpen(null), 140);
    };

    return (
        <nav className="ml-2 hidden items-center gap-0.5 xl:flex" aria-label="Main">
            {items.map((item) => {
                const active = isNavActive(url, item);
                const Icon = item.icon;
                if (!item.children?.length) {
                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            className={clsx(
                                'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium transition',
                                active
                                    ? 'bg-lantern-400/10 text-lantern-200 shadow-[inset_0_-2px_0_var(--color-lantern-400)]'
                                    : 'text-ink-300 hover:bg-ink-800 hover:text-ink-100',
                            )}
                        >
                            <Icon className="size-4" />
                            {item.label}
                        </Link>
                    );
                }

                const shown = open === item.href;
                return (
                    <div key={item.href} className="relative" onMouseEnter={() => show(item.href)} onMouseLeave={hideSoon}>
                        <div
                            className={clsx(
                                'flex items-stretch rounded-lg',
                                active
                                    ? 'bg-lantern-400/10 text-lantern-200 shadow-[inset_0_-2px_0_var(--color-lantern-400)]'
                                    : 'text-ink-300 hover:bg-ink-800 hover:text-ink-100',
                            )}
                        >
                            <Link href={item.href} aria-current={active ? 'page' : undefined} className="flex items-center gap-1.5 py-1.5 pr-1 pl-2.5 text-sm font-medium">
                                <Icon className="size-4" />
                                {item.label}
                            </Link>
                            <button
                                type="button"
                                aria-expanded={shown}
                                aria-haspopup="menu"
                                aria-label={`${item.label} menu`}
                                onClick={() => setOpen(shown ? null : item.href)}
                                className="rounded-r-lg pr-1.5 pl-0.5"
                            >
                                <ChevronDown className={clsx('size-3.5 transition', shown && 'rotate-180')} />
                            </button>
                        </div>
                        {shown && (
                            <div role="menu" className="absolute top-full left-0 z-50 w-60 pt-1.5">
                            <div className="rounded-xl border border-ink-700/80 bg-ink-900/95 p-1.5 shadow-2xl shadow-black/50 backdrop-blur-lg">
                                {item.children.map((child) => {
                                    const childActive = isNavActive(url, child);
                                    const ChildIcon = child.icon;
                                    return (
                                        <Link
                                            key={child.href}
                                            href={child.href}
                                            role="menuitem"
                                            aria-current={childActive ? 'page' : undefined}
                                            className={clsx(
                                                'flex items-center gap-3 rounded-lg px-2 py-2',
                                                childActive ? 'bg-lantern-400/15 text-lantern-100' : 'text-ink-200 hover:bg-ink-800',
                                            )}
                                        >
                                            <span className={clsx('grid size-8 shrink-0 place-items-center rounded-lg border', childActive ? 'border-lantern-400/40 bg-lantern-400/10 text-lantern-200' : 'border-ink-700 bg-ink-800 text-ink-300')}>
                                                <ChildIcon className="size-4" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-sm font-semibold">{child.label}</span>
                                                {child.hint && <span className="block truncate text-[11px] text-ink-400">{child.hint}</span>}
                                            </span>
                                        </Link>
                                    );
                                })}
                            </div>
                            </div>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}

function MobileNav({ items, url }: { items: NavEntry[]; url: string }) {
    const [open, setOpen] = useState<string | null>(null);
    const openItem = items.find((item) => item.href === open && item.children?.length);

    useEffect(() => {
        setOpen(null);
    }, [url]);

    return (
        <>
            {openItem && (
                <button type="button" aria-label="Close menu" className="fixed inset-0 z-30 bg-veil/45 xl:hidden" onClick={() => setOpen(null)} />
            )}
            <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-ink-700/70 bg-ink-900/95 backdrop-blur-lg xl:hidden" aria-label="Main">
                {openItem?.children && (
                    <div className="flex flex-col gap-1.5 border-b border-ink-700/70 px-3 py-2.5">
                        {openItem.children.map((child) => {
                            const childActive = isNavActive(url, child);
                            const ChildIcon = child.icon;
                            return (
                                <Link
                                    key={child.href}
                                    href={child.href}
                                    aria-current={childActive ? 'page' : undefined}
                                    className={clsx(
                                        'flex items-center gap-2.5 rounded-xl border px-3 py-2.5',
                                        childActive ? 'border-lantern-400/50 bg-lantern-400/10 text-lantern-100' : 'border-ink-700 bg-ink-800/70 text-ink-200',
                                    )}
                                >
                                    <ChildIcon className="size-4 shrink-0" />
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold">{child.label}</span>
                                        {child.hint && <span className="block truncate text-[10px] text-ink-400">{child.hint}</span>}
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                )}
                <div className="mx-auto flex max-w-lg">
                    {items.map((item) => {
                        const active = isNavActive(url, item);
                        const Icon = item.icon;
                        const shown = open === item.href;
                        if (!item.children?.length) {
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={clsx('flex flex-1 flex-col items-center gap-0.5 py-2 text-[10px] font-medium', active ? 'text-lantern-300' : 'text-ink-400')}
                                >
                                    <Icon className="size-5" />
                                    {item.label}
                                </Link>
                            );
                        }
                        return (
                            <button
                                key={item.href}
                                type="button"
                                aria-expanded={shown}
                                onClick={() => setOpen(shown ? null : item.href)}
                                className={clsx('flex flex-1 flex-col items-center gap-0.5 py-2 text-[10px] font-medium', active || shown ? 'text-lantern-300' : 'text-ink-400')}
                            >
                                <Icon className="size-5" />
                                <span className="flex items-center gap-0.5">
                                    {item.label}
                                    <ChevronDown className={clsx('size-3 transition', shown && 'rotate-180')} />
                                </span>
                            </button>
                        );
                    })}
                </div>
            </nav>
        </>
    );
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
                        <span className="absolute -top-1 -right-1 grid size-5 place-items-center rounded-full bg-lantern-400 text-[10px] font-bold text-on-accent">
                            <span className="absolute inset-0 animate-pulse-ring rounded-full bg-lantern-400" />
                            <span className="relative">{hero.unspent}</span>
                        </span>
                    )}
                </div>
                <div className="hidden w-40 md:block xl:hidden 2xl:block">
                    <div className="flex items-baseline justify-between gap-2">
                        <span className="truncate font-semibold text-ink-100 group-hover:text-lantern-200">{hero.name}</span>
                        <span className="shrink-0 text-[11px] text-ink-400 tabular-nums">
                            Lv {hero.level} · {hero.power}
                        </span>
                    </div>
                    <Bar value={hero.xp} max={xpMax} tone="arcane" className="mt-1 h-1.5" />
                    {hero.title && <TitleBadge title={hero.title} className="mt-1 max-w-full truncate" />}
                </div>
            </Link>
            {hero.injury_severity > 0 && (
                <Link href="/character" className="chip border-blood-500/50 bg-blood-500/10 text-blood-400" title="Injury">
                    🩸 {INJURY_LABELS[hero.injury_severity]} · {hero.injury_runs_left} runs
                </Link>
            )}
            <Link href="/market" className="rounded-lg border border-ink-700 bg-ink-900/60 px-3 py-1.5 hover:border-lantern-500/40">
                <Gold amount={hero.gold} />
            </Link>
            <Link
                href="/premium"
                className="hidden rounded-lg border border-ink-700 bg-ink-900/60 px-3 py-1.5 text-sm font-semibold text-moss-400 tabular-nums hover:border-moss-500/40 sm:block"
                title="Premium store (play money)"
            >
                {money(hero.cash)}
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
    const nav = props.auth.account?.is_admin ? [...NAV, ADMIN_NAV] : NAV;

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
                    <DesktopNav items={nav} url={url} />
                    <HeroBar />
                </div>
                <RunBanner />
            </header>

            <main className="mx-auto w-full max-w-7xl flex-1 px-4 pt-6 pb-28 xl:pb-12">{children}</main>

            <MobileNav items={NAV} url={url} />

            <footer className="border-t border-ink-800 px-4 py-4 pb-24 text-center text-xs text-ink-500 xl:pb-4">
                <p>
                    <Swords className="mr-1 inline size-3" /> Delvebound · expeditions depart when the keeper of Lanternford rings the bell
                </p>
                <p className="mt-1">
                    <Link href="/credits" className="underline decoration-ink-700 underline-offset-2 hover:text-lantern-200">
                        Item art by Lorc, Delapouite, Skoll, and Carl Olsen
                    </Link>
                    <span className="mx-1.5 text-ink-700">·</span>
                    game-icons.net, CC BY 3.0
                </p>
            </footer>
            <Toasts />
            <GameDialog />
        </div>
    );
}
