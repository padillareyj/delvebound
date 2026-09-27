import { Head, Link, router } from '@inertiajs/react';
import clsx from 'clsx';
import { CheckCheck, Gift, Sparkles } from 'lucide-react';
import { Empty, Gold, OutcomeChip, PageHeader, Pager, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { formatDateTime, useTimezone } from '@/lib/format';
import type { MailRow } from '@/types';

interface Props {
    mail: MailRow[];
    page: number;
    pages: number;
    total: number;
    filters: { outcome: string | null; unread: boolean };
}

const OUTCOMES = [
    { value: '', label: 'All' },
    { value: 'cleared', label: 'Cleared' },
    { value: 'retreated', label: 'Retreated' },
    { value: 'defeated', label: 'Defeated' },
    { value: 'rested', label: 'Rested' },
    { value: 'champion', label: 'Champion' },
    { value: 'eliminated', label: 'Eliminated' },
];

function query(filters: Props['filters'], page = 1) {
    const q = new URLSearchParams();
    if (filters.outcome) q.set('outcome', filters.outcome);
    if (filters.unread) q.set('unread', '1');
    if (page > 1) q.set('page', String(page));
    const s = q.toString();
    return s ? `/mail?${s}` : '/mail';
}

export default function MailIndex({ mail, page, pages, total, filters }: Props) {
    const tz = useTimezone();
    const go = (f: Props['filters']) => router.get(query(f), {}, { preserveScroll: true, preserveState: true });

    return (
        <>
            <Head title="Mail" />
            <PageHeader title="Mail" subtitle={`${total} letter${total === 1 ? '' : 's'} from the trail.`}>
                <button className="btn-ghost" onClick={() => act('/mail/read-all')}>
                    <CheckCheck className="size-4" /> Mark all read
                </button>
            </PageHeader>

            <Panel
                bodyClass="p-0"
                title={
                    <div className="flex flex-wrap items-center gap-1 font-sans normal-case tracking-normal">
                        {OUTCOMES.map((o) => (
                            <button
                                key={o.value}
                                onClick={() => go({ ...filters, outcome: o.value || null })}
                                className={clsx(
                                    'rounded-md px-2.5 py-1 text-xs font-semibold',
                                    (filters.outcome ?? '') === o.value ? 'bg-lantern-400/15 text-lantern-200' : 'text-ink-400 hover:text-ink-100',
                                )}
                            >
                                {o.label}
                            </button>
                        ))}
                    </div>
                }
                action={
                    <label className="flex cursor-pointer items-center gap-2 text-xs text-ink-300">
                        <input type="checkbox" checked={filters.unread} onChange={(e) => go({ ...filters, unread: e.target.checked })} className="accent-lantern-400" />
                        Unread only
                    </label>
                }
            >
                {mail.length === 0 ? (
                    <Empty icon="📭" title="No letters here">Reports arrive after each run.</Empty>
                ) : (
                    <ul className="divide-y divide-ink-700/60">
                        {mail.map((m) => (
                            <li key={m.id}>
                                <Link href={`/mail/${m.id}`} className={clsx('flex items-center gap-4 px-4 py-3 transition hover:bg-ink-800/60', !m.is_read && 'bg-lantern-400/[0.03]')}>
                                    <span className={clsx('size-2.5 shrink-0 rounded-full', m.is_read ? 'bg-transparent ring-1 ring-ink-600' : 'bg-lantern-400 shadow-[0_0_8px_var(--color-lantern-400)]')} />
                                    <div className="min-w-0 flex-1">
                                        <div className={clsx('truncate', m.is_read ? 'text-ink-300' : 'font-semibold text-ink-100')}>{m.subject}</div>
                                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-ink-400">
                                            <OutcomeChip outcome={m.outcome} />
                                            {m.level_up && <span className="chip border-arcane-400/50 text-arcane-400"><Sparkles className="size-3" /> Level up</span>}
                                            {m.items > 0 && <span className="flex items-center gap-1"><Gift className="size-3" /> {m.items} item{m.items === 1 ? '' : 's'}</span>}
                                        </div>
                                    </div>
                                    <div className="hidden text-right text-xs sm:block">
                                        {m.xp != null && m.xp > 0 && <div className="font-semibold text-arcane-400">+{m.xp} XP</div>}
                                        {m.gold != null && m.gold > 0 && <Gold amount={m.gold} className="text-xs" />}
                                    </div>
                                    <div className="w-24 text-right text-xs whitespace-nowrap text-ink-500">{formatDateTime(m.created_at, tz)}</div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="pb-4">
                    <Pager page={page} pages={pages} href={(p) => query(filters, p)} />
                </div>
            </Panel>
        </>
    );
}
