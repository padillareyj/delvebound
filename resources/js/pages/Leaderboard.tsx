import { Head, Link, router, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { Bot, Crown, Medal } from 'lucide-react';
import { GearGlyphs, TitleBadge } from '@/components/Premium';
import SectionNav from '@/components/SectionNav';
import { Gold, PageHeader, Panel } from '@/components/ui';
import { RANKS_NAV } from '@/lib/nav';
import type { PremiumPiece, SharedProps } from '@/types';

interface Row {
    id: number;
    name: string;
    title: PremiumPiece | null;
    glyphs: string[];
    is_bot: boolean;
    level: number;
    power: number;
    total_xp: number;
    gold: number;
    kills: number;
    boss_kills: number;
    trips_cleared: number;
    defeats: number;
    best_place: string | null;
}

const SORTS = [
    { key: 'level', label: 'Level' },
    { key: 'power', label: 'Power' },
    { key: 'gold', label: 'Gold' },
    { key: 'bosses', label: 'Bosses' },
    { key: 'clears', label: 'Clears' },
    { key: 'kills', label: 'Kills' },
    { key: 'defeats', label: 'Defeats' },
];

const PODIUM = ['text-rarity-legendary', 'text-ink-200', 'text-lantern-600'];

export default function Leaderboard({ sort, rows }: { sort: string; rows: Row[] }) {
    const { hero } = usePage<SharedProps>().props;
    return (
        <>
            <Head title="Leaderboard" />
            <SectionNav entry={RANKS_NAV} />
            <PageHeader title="Hall of Lanterns" subtitle="Every adventurer in the realm, human and otherwise.">
                <div className="flex flex-wrap rounded-lg border border-ink-700 bg-ink-900/60 p-1">
                    {SORTS.map((s) => (
                        <Link
                            key={s.key}
                            href={`/leaderboard?sort=${s.key}`}
                            preserveScroll
                            className={clsx('rounded-md px-3 py-1.5 text-xs font-semibold', sort === s.key ? 'bg-lantern-400 text-ink-950' : 'text-ink-300 hover:text-ink-100')}
                        >
                            {s.label}
                        </Link>
                    ))}
                </div>
            </PageHeader>

            <Panel bodyClass="p-0">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-[11px] uppercase tracking-wider text-ink-400">
                            <tr className="border-b border-ink-700/60">
                                <th className="w-12 px-4 py-3">#</th>
                                <th className="px-4 py-3">Adventurer</th>
                                <th className="px-4 py-3 text-right">Level</th>
                                <th className="px-4 py-3 text-right">Power</th>
                                <th className="px-4 py-3 text-right">Gold</th>
                                <th className="px-4 py-3 text-right">Kills</th>
                                <th className="px-4 py-3 text-right">Bosses</th>
                                <th className="px-4 py-3 text-right">Clears</th>
                                <th className="px-4 py-3 text-right">Defeats</th>
                                <th className="hidden px-4 py-3 md:table-cell">Furthest</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-ink-800">
                            {rows.map((r, i) => (
                                <tr
                                    key={r.id}
                                    className={clsx('cursor-pointer transition hover:bg-ink-800/50', r.id === hero?.id && 'bg-lantern-400/[0.06]')}
                                    onClick={(e) => {
                                        if ((e.target as HTMLElement).closest('a')) return;
                                        router.visit(`/leaderboard/${r.id}?sort=${sort}`);
                                    }}
                                >
                                    <td className="px-4 py-3 font-display font-bold">
                                        {i < 3 ? <Medal className={clsx('size-5', PODIUM[i])} /> : <span className="text-ink-500">{i + 1}</span>}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={`/leaderboard/${r.id}?sort=${sort}`} className={clsx('font-semibold hover:underline', r.id === hero?.id ? 'text-lantern-200' : 'text-ink-100')}>{r.name}</Link>
                                        {r.is_bot && <Bot className="ml-1.5 inline size-3.5 text-ink-500" aria-label="bot" />}
                                        {r.id === hero?.id && <span className="ml-2 text-[10px] font-bold text-lantern-400 uppercase">you</span>}
                                        {(r.title || r.glyphs.length > 0) && (
                                            <div className="mt-0.5 flex items-center gap-2">
                                                <TitleBadge title={r.title} />
                                                <GearGlyphs glyphs={r.glyphs} />
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right font-bold tabular-nums">{r.level}</td>
                                    <td className="px-4 py-3 text-right font-bold tabular-nums text-lantern-200">{r.power}</td>
                                    <td className="px-4 py-3 text-right"><Gold amount={r.gold} /></td>
                                    <td className="px-4 py-3 text-right tabular-nums">{r.kills}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {r.boss_kills > 0 ? <span className="text-rarity-legendary"><Crown className="mr-1 inline size-3" />{r.boss_kills}</span> : 0}
                                    </td>
                                    <td className="px-4 py-3 text-right text-moss-400 tabular-nums">{r.trips_cleared}</td>
                                    <td className="px-4 py-3 text-right text-blood-400 tabular-nums">{r.defeats}</td>
                                    <td className="hidden px-4 py-3 text-ink-400 md:table-cell">{r.best_place ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </>
    );
}
