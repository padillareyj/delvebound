import { Link } from '@inertiajs/react';
import clsx from 'clsx';
import type { ReactNode } from 'react';
import { OUTCOME_META } from '@/lib/format';
import type { Outcome } from '@/types';

export function Panel({ title, action, className, children, bodyClass }: {
    title?: ReactNode;
    action?: ReactNode;
    className?: string;
    bodyClass?: string;
    children: ReactNode;
}) {
    return (
        <section className={clsx('panel animate-rise', className)}>
            {(title || action) && (
                <header className="flex items-center justify-between gap-3 border-b border-ink-700/70 px-4 py-3">
                    {title && <h2 className="panel-title">{title}</h2>}
                    {action}
                </header>
            )}
            <div className={clsx('p-4', bodyClass)}>{children}</div>
        </section>
    );
}

export function PageHeader({ title, subtitle, children }: { title: string; subtitle?: ReactNode; children?: ReactNode }) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 className="text-3xl font-extrabold text-lantern-200 text-shadow-glow sm:text-4xl">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-ink-300">{subtitle}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

export function Bar({ value, max, tone = 'lantern', className, label, animate = true }: {
    value: number;
    max: number;
    tone?: 'lantern' | 'blood' | 'moss' | 'arcane' | 'frost';
    className?: string;
    label?: ReactNode;
    animate?: boolean;
}) {
    const pct = max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0;
    const tones = {
        lantern: 'from-lantern-600 to-lantern-400',
        blood: 'from-blood-600 to-blood-400',
        moss: 'from-moss-500 to-moss-400',
        arcane: 'from-arcane-500 to-arcane-400',
        frost: 'from-sky-600 to-frost-400',
    };
    return (
        <div className={clsx('relative h-2.5 overflow-hidden rounded-full bg-ink-950/80 ring-1 ring-ink-700', className)}>
            <div
                className={clsx('h-full rounded-full bg-gradient-to-r', tones[tone], animate && 'transition-[width] duration-500 ease-out')}
                style={{ width: `${pct}%` }}
            />
            {label && <span className="absolute inset-0 grid place-items-center text-[10px] font-bold text-white/90 drop-shadow">{label}</span>}
        </div>
    );
}

export function OutcomeChip({ outcome }: { outcome: Outcome | null }) {
    if (!outcome) return null;
    const meta = OUTCOME_META[outcome] ?? OUTCOME_META.order_rejected;
    return <span className={clsx('chip', meta.chip)}>{meta.label}</span>;
}

export function Empty({ icon, title, children }: { icon?: ReactNode; title: string; children?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 px-6 py-10 text-center">
            {icon && <div className="text-4xl opacity-70">{icon}</div>}
            <p className="font-display text-lg text-ink-200">{title}</p>
            {children && <div className="max-w-sm text-sm text-ink-400">{children}</div>}
        </div>
    );
}

export function StatTile({ label, value, hint, accent }: { label: string; value: ReactNode; hint?: ReactNode; accent?: string }) {
    return (
        <div className="rounded-lg border border-ink-700/70 bg-ink-900/60 px-3 py-2.5">
            <div className="text-[11px] font-semibold uppercase tracking-wider text-ink-400">{label}</div>
            <div className={clsx('mt-0.5 text-lg font-bold tabular-nums', accent ?? 'text-ink-100')}>{value}</div>
            {hint && <div className="text-xs text-ink-400">{hint}</div>}
        </div>
    );
}

export function Gold({ amount, className }: { amount: number; className?: string }) {
    return (
        <span className={clsx('inline-flex items-center gap-1 font-semibold tabular-nums text-lantern-300', className)}>
            <span className="inline-block size-2.5 rounded-full bg-gradient-to-br from-lantern-400 to-lantern-600 shadow-[0_0_6px_color-mix(in_oklab,var(--color-lantern-400)_70%,transparent)]" />
            {new Intl.NumberFormat('en-US').format(amount)}
        </span>
    );
}

export function Pager({ page, pages, href }: { page: number; pages: number; href: (p: number) => string }) {
    if (pages <= 1) return null;
    return (
        <nav className="flex items-center justify-center gap-2 pt-4 text-sm">
            <Link href={href(page - 1)} preserveScroll className={clsx('btn-ghost', page <= 1 && 'pointer-events-none opacity-40')}>
                ← Newer
            </Link>
            <span className="px-2 text-ink-300 tabular-nums">
                Page {page} / {pages}
            </span>
            <Link href={href(page + 1)} preserveScroll className={clsx('btn-ghost', page >= pages && 'pointer-events-none opacity-40')}>
                Older →
            </Link>
        </nav>
    );
}

export function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs font-medium text-blood-400">{message}</p>;
}
