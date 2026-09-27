import { Head } from '@inertiajs/react';
import { Bot as BotIcon } from 'lucide-react';
import { Gold, OutcomeChip, PageHeader, Panel } from '@/components/ui';
import { INJURY_LABELS, STAT_NAMES, timeAgo } from '@/lib/format';
import type { Outcome, StatKey, Stats } from '@/types';

interface Decision {
    run_id: number;
    created_at: string;
    decision: Record<string, unknown> | null;
}

interface BotRow {
    id: number;
    name: string;
    level: number;
    gold: number;
    kills: number;
    boss_kills: number;
    trips_cleared: number;
    defeats: number;
    injury: number;
    stats: Stats;
    archetype: string;
    profile: string;
    description: string | null;
    preferred_scaling: string | null;
    risk_offset: number;
    buff_preference: string | null;
    last_trip: { outcome: Outcome; xp: number; place: string } | null;
    decisions: Decision[];
}

const ARCHETYPE_GLYPH: Record<string, string> = {
    warrior: '⚔️',
    berserker: '🪓',
    tank: '🛡️',
    rogue: '🗡️',
    ranger: '🏹',
    mage: '🔮',
    gambler: '🎲',
    treasure: '💰',
};

const pretty = (code: string) => code.split('_').map((w) => w[0]?.toUpperCase() + w.slice(1)).join(' ');

function summarize(d: Record<string, unknown> | null): string {
    if (!d) return 'No decision recorded.';
    const parts: string[] = [];
    if (typeof d.place === 'string') parts.push(d.place === 'town' ? 'rested in town' : `headed to ${pretty(d.place)}`);
    if (d.points && typeof d.points === 'object') {
        const s = Object.entries(d.points as Record<string, number>).filter(([, v]) => Number(v) > 0);
        if (s.length) parts.push(`trained ${s.map(([k, v]) => `${v} ${k.toUpperCase()}`).join(', ')}`);
    }
    if (Array.isArray(d.bought) && d.bought.length) {
        parts.push(`bought ${(d.bought as { code?: string }[]).map((b) => pretty(b.code ?? 'item')).join(', ')}`);
    }
    if (Array.isArray(d.sold) && d.sold.length) parts.push(`sold ${d.sold.length} item${d.sold.length === 1 ? '' : 's'}`);
    return parts.length ? parts.join(' · ') : 'Stayed the course.';
}

export default function Bots({ bots }: { bots: BotRow[] }) {
    return (
        <>
            <Head title="Rivals" />
            <PageHeader title="Rival Adventurers" subtitle="Six rule-bound souls who share the realm with you. They shop, gear up, and choose their own paths each run." />
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {bots.map((b) => {
                    const top = Math.max(...Object.values(b.stats));
                    return (
                        <Panel key={b.id} bodyClass="p-0">
                            <div className="flex items-start gap-3 border-b border-ink-700/60 p-4">
                                <div className="grid size-14 shrink-0 place-items-center rounded-xl border border-ink-600 bg-ink-900 text-3xl">
                                    {ARCHETYPE_GLYPH[b.archetype] ?? <BotIcon className="size-7 text-ink-400" />}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center justify-between gap-2">
                                        <h2 className="truncate text-lg font-bold text-ink-100">{b.name}</h2>
                                        <span className="font-display text-xl font-extrabold text-lantern-300">{b.level}</span>
                                    </div>
                                    <div className="text-xs text-lantern-300 capitalize">{b.profile} · {b.archetype}</div>
                                    {b.description && <p className="mt-1 line-clamp-2 text-xs text-ink-400">{b.description}</p>}
                                </div>
                            </div>
                            <div className="space-y-3 p-4">
                                <div className="space-y-1">
                                    {(Object.keys(b.stats) as StatKey[]).map((k) => (
                                        <div key={k} className="flex items-center gap-2 text-xs">
                                            <span className="w-8 font-bold text-ink-400">{STAT_NAMES[k].short}</span>
                                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-950">
                                                <div className="h-full rounded-full bg-gradient-to-r from-lantern-600 to-lantern-300" style={{ width: `${(b.stats[k] / top) * 100}%` }} />
                                            </div>
                                            <span className="w-6 text-right tabular-nums">{b.stats[k]}</span>
                                        </div>
                                    ))}
                                </div>
                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-300">
                                    <Gold amount={b.gold} className="text-xs" />
                                    <span>{b.kills} kills</span>
                                    <span className="text-moss-400">{b.trips_cleared} clears</span>
                                    <span className="text-blood-400">{b.defeats} defeats</span>
                                    {b.injury > 0 && <span className="text-blood-400">🩸 {INJURY_LABELS[b.injury]}</span>}
                                </div>
                                {b.last_trip && (
                                    <div className="flex items-center justify-between rounded-lg bg-ink-900/60 px-3 py-2 text-xs">
                                        <span className="text-ink-300">Last: {b.last_trip.place}</span>
                                        <span className="flex items-center gap-2">
                                            <OutcomeChip outcome={b.last_trip.outcome} />
                                            <span className="text-arcane-400">+{b.last_trip.xp} XP</span>
                                        </span>
                                    </div>
                                )}
                                {b.decisions.length > 0 && (
                                    <div>
                                        <div className="label">Recent thinking</div>
                                        <ul className="space-y-1 text-[11px] text-ink-400">
                                            {b.decisions.map((d) => (
                                                <li key={d.run_id}>
                                                    <span className="text-ink-500">Run #{d.run_id} · {timeAgo(d.created_at)}:</span> {summarize(d.decision)}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        </Panel>
                    );
                })}
            </div>
        </>
    );
}
