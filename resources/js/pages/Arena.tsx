import { Head, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { Swords } from 'lucide-react';
import { useState } from 'react';
import SectionNav from '@/components/SectionNav';
import { PageHeader, Panel } from '@/components/ui';
import { RANKS_NAV } from '@/lib/nav';
import { act } from '@/lib/act';
import { formatDateTime, useTimezone } from '@/lib/format';
import type { SharedProps } from '@/types';

interface Fighter {
    id: number;
    name: string;
    hp: number;
}

interface LogEvent {
    r: number;
    a?: string;
    t?: string;
    ev: string;
    dmg?: number;
    crit?: boolean;
    thp?: number;
    heal?: number;
    winner?: string;
}

interface Match {
    slot: number;
    reason: 'knockout' | 'time' | 'bye';
    rounds: number;
    a: Fighter;
    b: Fighter | null;
    winner: string;
    mine: boolean;
    log: LogEvent[];
}

interface Bracket {
    id: number;
    seed: string;
    finished_at: string | null;
    entrants: number;
    champion: string | null;
    champion_bot: boolean;
    rounds: Match[][];
}

interface RosterRow {
    id: number;
    name: string;
    level: number;
    is_bot: boolean;
    auto: boolean;
}

interface HistoryRow {
    id: number;
    finished_at: string | null;
    entrants: number;
    champion: string;
    champion_bot: boolean;
}

function logLine(ev: LogEvent): string {
    if (ev.ev === 'dodge') return `R${ev.r}  ${ev.t} dodges ${ev.a}`;
    if (ev.ev === 'potion') return `R${ev.r}  ${ev.a} drinks a potion (+${ev.heal} HP, now ${ev.thp})`;
    if (ev.ev === 'time') return `R${ev.r}  The round limit falls. ${ev.winner} is still standing.`;
    const crit = ev.crit ? ' critical' : '';
    const leech = ev.heal ? `, lifesteal +${ev.heal}` : '';
    return `R${ev.r}  ${ev.a} hits ${ev.t} for ${ev.dmg}${crit} (${ev.thp} HP left${leech})`;
}

function MatchCard({ match }: { match: Match }) {
    const [open, setOpen] = useState(false);
    if (match.reason === 'bye') {
        return (
            <div className={clsx('rounded-lg border px-3 py-2 text-sm', match.mine ? 'border-lantern-400/50 bg-lantern-400/10' : 'border-ink-700 bg-ink-900/40')}>
                <span className="font-semibold text-ink-100">{match.a.name}</span>
                <span className="text-ink-400"> advances on a bye</span>
            </div>
        );
    }
    return (
        <div className={clsx('rounded-lg border px-3 py-2', match.mine ? 'border-lantern-400/50 bg-lantern-400/10' : 'border-ink-700 bg-ink-900/40')}>
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <span>
                    <span className={clsx('font-semibold', match.winner === match.a.name ? 'text-lantern-200' : 'text-ink-300')}>{match.a.name}</span>
                    <span className="text-ink-500"> vs </span>
                    <span className={clsx('font-semibold', match.winner === match.b?.name ? 'text-lantern-200' : 'text-ink-300')}>{match.b?.name}</span>
                </span>
                <span className="text-xs text-ink-400">
                    {match.reason === 'time' ? 'time limit' : 'knockout'} · {match.rounds} rounds
                </span>
            </div>
            <div className="mt-1 text-xs text-ink-400">
                {match.winner} wins · {match.a.name} {match.a.hp} HP
                {match.b && ` · ${match.b.name} ${match.b.hp} HP`}
            </div>
            {match.log.length > 0 && (
                <button className="btn-ghost mt-2 px-2 py-1 text-xs" onClick={() => setOpen((v) => !v)}>
                    {open ? 'Hide the blows' : 'Show the blows'}
                </button>
            )}
            {open && (
                <pre className="scroll-thin mt-2 max-h-48 overflow-auto font-mono text-[11px] leading-relaxed text-ink-300">
                    {match.log.map(logLine).join('\n')}
                </pre>
            )}
        </div>
    );
}

export default function Arena({ signedUp, roster, bracket, history }: {
    signedUp: boolean;
    roster: RosterRow[];
    bracket: Bracket | null;
    history: HistoryRow[];
}) {
    const tz = useTimezone();
    const hero = usePage<SharedProps>().props.hero;
    const version = hero?.row_version ?? 0;

    return (
        <>
            <Head title="Arena" />
            <SectionNav entry={RANKS_NAV} />
            <PageHeader
                title="The Arena"
                subtitle="Sign up, and the next bracket draws you at random against whoever else is waiting — rivals included. You fight until one of you drops to 0 HP. Winners move on. A knockout here does not wound you."
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="space-y-4">
                    <Panel
                        title="Your signup"
                        action={
                            signedUp ? (
                                <button className="btn-ghost" onClick={() => act('/arena/enter', { row_version: version }, { method: 'delete' })}>
                                    Withdraw
                                </button>
                            ) : (
                                <button className="btn-primary" onClick={() => act('/arena/enter', { row_version: version })}>
                                    <Swords className="size-4" /> Enter the arena
                                </button>
                            )
                        }
                    >
                        <p className="text-sm text-ink-300">
                            {signedUp
                                ? 'You are waiting on the sand. Gear and potions you carry now are what you fight with. There is no button to start the bouts — the keeper rings them with the arena command.'
                                : 'You are not in the next bracket. Entering does not send you on an expedition; the map order is separate.'}
                        </p>
                    </Panel>

                    {bracket ? (
                        <Panel title={`Arena #${bracket.id}`} action={<span className="text-xs text-ink-400">{formatDateTime(bracket.finished_at, tz)} · seed {bracket.seed}</span>}>
                            <p className="mb-4 text-sm text-ink-200">
                                Champion: <span className="font-semibold text-lantern-200">{bracket.champion}</span>
                                {bracket.champion_bot ? ' 🤖' : ''}
                                <span className="text-ink-400"> · {bracket.entrants} entrants</span>
                            </p>
                            <div className="space-y-4">
                                {bracket.rounds.map((round, i) => (
                                    <div key={i}>
                                        <h3 className="mb-2 text-xs font-semibold tracking-wide text-ink-400 uppercase">Round {i + 1}</h3>
                                        <div className="grid gap-2">
                                            {round.map((match) => (
                                                <MatchCard key={match.slot} match={match} />
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    ) : (
                        <Panel title="Latest bracket">
                            <p className="text-sm text-ink-400">No arena has been fought yet.</p>
                        </Panel>
                    )}
                </div>

                <div className="space-y-4">
                    <Panel title="Who steps in">
                        <ul className="space-y-2">
                            {roster.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-2 text-sm">
                                    <span className="truncate text-ink-100">
                                        {c.name}
                                        {c.is_bot && <span className="ml-1 text-ink-500">🤖</span>}
                                    </span>
                                    <span className="shrink-0 text-xs text-ink-400">
                                        Lv {c.level}
                                        {c.auto ? ' · always' : ' · signed up'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-xs text-ink-500">Rivals enter every arena. Humans enter only when they sign up.</p>
                    </Panel>
                    <Panel title="Past champions">
                        {history.length === 0 ? (
                            <p className="text-sm text-ink-400">The sand is still smooth.</p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {history.map((h) => (
                                    <li key={h.id} className="flex items-baseline justify-between gap-2">
                                        <span className="text-ink-100">
                                            #{h.id} {h.champion}
                                            {h.champion_bot ? ' 🤖' : ''}
                                        </span>
                                        <span className="text-xs text-ink-500">{h.entrants} fighters</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}
