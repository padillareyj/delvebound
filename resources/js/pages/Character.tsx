import { Head } from '@inertiajs/react';
import clsx from 'clsx';
import { Minus, Plus, RotateCcw, Shield, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import { RarityName, slotGlyph } from '@/components/ItemCard';
import { Bar, Gold, PageHeader, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { DERIVED_LABELS, INJURY_LABELS, SLOT_LABELS, STAT_NAMES, derivedDelta, derivedValue, formatDate, useTimezone } from '@/lib/format';
import type { Derived, Item, StatKey, Stats } from '@/types';

interface Props {
    character: {
        id: number;
        name: string;
        level: number;
        xp: number;
        total_xp: number;
        xp_to_next: number | null;
        gold: number;
        stats: Stats;
        unspent: number;
        retreat_threshold_pct: number;
        potion_threshold_pct: number;
        auto_repeat: boolean;
        injury_severity: number;
        injury_runs_left: number;
        row_version: number;
        best_place: string | null;
        created_at: string;
    };
    derived: Required<Derived>;
    baseDerived: Required<Derived>;
    buffs: string[];
    gear: Record<string, Item>;
    perPoint: Record<StatKey, Record<string, number>>;
    respecCost: number;
    pointPool: number;
    baseStat: number;
    caps: Record<string, number>;
}

const STATS: StatKey[] = ['str', 'dex', 'int', 'vit', 'luk'];
const SHOWN = ['hp', 'attack', 'defense', 'mres', 'crit', 'crit_mult', 'dodge', 'speed', 'regen', 'luck', 'gold_find'] as const;
const EMPTY: Stats = { str: 0, dex: 0, int: 0, vit: 0, luk: 0 };
const BUFF_LABEL: Record<string, string> = { might: '💪 Might', stoneskin: '🪨 Stoneskin', luck: '🍀 Luck' };

export default function Character({ character: c, derived, baseDerived, buffs, gear, perPoint, respecCost, pointPool, baseStat }: Props) {
    const tz = useTimezone();
    const [alloc, setAlloc] = useState<Stats>(EMPTY);
    const [saving, setSaving] = useState(false);
    const [prep, setPrep] = useState({
        retreat_threshold_pct: c.retreat_threshold_pct,
        potion_threshold_pct: c.potion_threshold_pct,
        auto_repeat: c.auto_repeat,
    });
    const spent = STATS.reduce((n, s) => n + alloc[s], 0);
    const left = c.unspent - spent;

    const preview = useMemo(() => {
        const out: Record<string, number> = {};
        for (const s of STATS) {
            for (const [k, v] of Object.entries(perPoint[s] ?? {})) out[k] = (out[k] ?? 0) + v * alloc[s];
        }
        return out;
    }, [alloc, perPoint]);

    const prepDirty =
        prep.retreat_threshold_pct !== c.retreat_threshold_pct ||
        prep.potion_threshold_pct !== c.potion_threshold_pct ||
        prep.auto_repeat !== c.auto_repeat;

    const bump = (s: StatKey, d: number) =>
        setAlloc((a) => ({ ...a, [s]: Math.max(0, a[s] + (d > 0 ? Math.min(d, left) : d)) }));

    const spend = () => {
        setSaving(true);
        act('/character/points', { points: alloc, row_version: c.row_version }, {
            onFinish: () => {
                setSaving(false);
                setAlloc(EMPTY);
            },
        });
    };

    return (
        <>
            <Head title="Hero" />
            <PageHeader
                title={c.name}
                subtitle={<>Level {c.level} adventurer · since {formatDate(c.created_at, tz)}{c.best_place && <> · furthest: {c.best_place}</>}</>}
            >
                {buffs.map((b) => (
                    <span key={b} className="chip border-moss-500/40 bg-moss-500/10 text-moss-400">{BUFF_LABEL[b] ?? b}</span>
                ))}
                {c.injury_severity > 0 && (
                    <span className="chip border-blood-500/50 bg-blood-500/10 text-blood-400">
                        🩸 {INJURY_LABELS[c.injury_severity]} ({c.injury_runs_left} runs)
                    </span>
                )}
            </PageHeader>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
                <div className="space-y-6 lg:col-span-3">
                    <Panel
                        title="Attributes"
                        action={
                            <span className={clsx('chip', c.unspent > 0 ? 'border-lantern-400/50 bg-lantern-400/10 text-lantern-200' : 'border-ink-600 text-ink-400')}>
                                <Sparkles className="size-3" /> {left} / {c.unspent} points
                            </span>
                        }
                    >
                        <div className="space-y-2">
                            {STATS.map((s) => {
                                const value = c.stats[s] + alloc[s];
                                const gains = Object.entries(perPoint[s] ?? {});
                                return (
                                    <div key={s} className={clsx('flex items-center gap-3 rounded-lg border px-3 py-2.5 transition', alloc[s] ? 'border-lantern-400/40 bg-lantern-400/5' : 'border-ink-700/70 bg-ink-900/40')}>
                                        <div className="w-12 font-display text-sm font-bold text-lantern-300">{STAT_NAMES[s].short}</div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-baseline gap-2">
                                                <span className="text-sm font-semibold text-ink-100">{STAT_NAMES[s].name}</span>
                                                <span className="hidden truncate text-xs text-ink-400 sm:inline">{STAT_NAMES[s].blurb}</span>
                                            </div>
                                            <div className="mt-0.5 flex flex-wrap gap-x-3 text-[11px] text-ink-400">
                                                {gains.slice(0, 4).map(([k, v]) => (
                                                    <span key={k}>
                                                        <span className="text-moss-400">{derivedDelta(k, v)}</span> {DERIVED_LABELS[k] ?? k}
                                                    </span>
                                                ))}
                                                <span className="text-ink-500">per point</span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-1.5">
                                            <button className="btn-ghost size-8 p-0" disabled={!alloc[s]} onClick={() => bump(s, -1)} aria-label={`Remove ${s}`}>
                                                <Minus className="size-4" />
                                            </button>
                                            <span className="w-12 text-center text-lg font-bold tabular-nums">
                                                {value}
                                                {alloc[s] > 0 && <span className="block text-[10px] leading-none text-moss-400">+{alloc[s]}</span>}
                                            </span>
                                            <button className="btn-ghost size-8 p-0" disabled={left <= 0} onClick={() => bump(s, 1)} aria-label={`Add ${s}`}>
                                                <Plus className="size-4" />
                                            </button>
                                            <button className="btn-ghost h-8 px-2 text-xs" disabled={left <= 0} onClick={() => bump(s, left)}>
                                                Max
                                            </button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <p className="text-xs text-ink-400">
                                Each stat starts at {baseStat}. You have earned {pointPool} points in total by level {c.level}.
                            </p>
                            <div className="flex gap-2">
                                <button className="btn-ghost" disabled={!spent} onClick={() => setAlloc(EMPTY)}>Clear</button>
                                <button className="btn-primary" disabled={!spent || saving} onClick={spend}>
                                    Spend {spent || ''} point{spent === 1 ? '' : 's'}
                                </button>
                            </div>
                        </div>
                    </Panel>

                    <Panel title="Expedition orders">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Slider
                                label="Retreat when HP falls below"
                                value={prep.retreat_threshold_pct}
                                max={90}
                                tone="text-lantern-300"
                                hint={prep.retreat_threshold_pct === 0 ? 'Never retreat. Fight to the last breath.' : 'Try to flee between rounds once HP drops this low.'}
                                onChange={(v) => setPrep((p) => ({ ...p, retreat_threshold_pct: v }))}
                            />
                            <Slider
                                label="Drink a potion below"
                                value={prep.potion_threshold_pct}
                                max={100}
                                tone="text-moss-400"
                                hint="Healing potions from your belt trigger at this HP."
                                onChange={(v) => setPrep((p) => ({ ...p, potion_threshold_pct: v }))}
                            />
                        </div>
                        <label className="mt-5 flex cursor-pointer items-center justify-between gap-4 rounded-lg border border-ink-700 bg-ink-900/40 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold text-ink-100">Auto-repeat</div>
                                <div className="text-xs text-ink-400">With no order, head back to the last place you visited.</div>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                aria-checked={prep.auto_repeat}
                                onClick={() => setPrep((p) => ({ ...p, auto_repeat: !p.auto_repeat }))}
                                className={clsx('relative h-6 w-11 rounded-full transition', prep.auto_repeat ? 'bg-lantern-500' : 'bg-ink-600')}
                            >
                                <span className={clsx('absolute top-0.5 size-5 rounded-full bg-white shadow transition', prep.auto_repeat ? 'left-5.5' : 'left-0.5')} />
                            </button>
                        </label>
                        <div className="mt-4 flex justify-end">
                            <button
                                className="btn-primary"
                                disabled={!prepDirty}
                                onClick={() => act('/character/prep', { ...prep, auto_repeat: prep.auto_repeat ? 1 : 0, row_version: c.row_version })}
                            >
                                Save orders
                            </button>
                        </div>
                    </Panel>
                </div>

                <div className="space-y-6 lg:col-span-2">
                    <Panel title="Combat profile">
                        <div className="mb-4">
                            <div className="mb-1 flex justify-between text-xs text-ink-400">
                                <span>Experience</span>
                                <span className="tabular-nums">{c.xp_to_next ? `${c.xp} / ${c.xp_to_next}` : 'Max level'}</span>
                            </div>
                            <Bar value={c.xp} max={c.xp_to_next ?? c.xp} tone="arcane" />
                        </div>
                        <dl className="divide-y divide-ink-700/50 text-sm">
                            {SHOWN.map((k) => {
                                const buffed = derived[k] - baseDerived[k];
                                const pv = preview[k];
                                return (
                                    <div key={k} className="flex items-center justify-between py-1.5">
                                        <dt className="text-ink-300">{DERIVED_LABELS[k]}</dt>
                                        <dd className="flex items-center gap-2 tabular-nums">
                                            {Math.abs(buffed) > 0.001 && <span className="text-[11px] text-moss-400">({derivedDelta(k, buffed)} buff)</span>}
                                            <span className="font-semibold text-ink-100">{derivedValue(k, derived[k])}</span>
                                            {pv ? <span className="text-xs font-semibold text-moss-400">≈{derivedDelta(k, pv)}</span> : null}
                                        </dd>
                                    </div>
                                );
                            })}
                            <div className="flex items-center justify-between py-1.5">
                                <dt className="text-ink-300">Damage type</dt>
                                <dd className={clsx('font-semibold capitalize', derived.damage_type === 'magic' ? 'text-arcane-400' : 'text-ink-100')}>{derived.damage_type}</dd>
                            </div>
                        </dl>
                    </Panel>

                    <Panel title="Equipped">
                        <ul className="space-y-1.5">
                            {['weapon', 'offhand', 'head', 'chest', 'hands', 'feet', 'ring1', 'ring2', 'amulet'].map((slot) => {
                                const item = gear[slot];
                                return (
                                    <li key={slot} className="flex items-center gap-3 text-sm">
                                        <span className="w-6 text-center">{slotGlyph(slot)}</span>
                                        <span className="w-16 text-xs text-ink-400">{SLOT_LABELS[slot]}</span>
                                        {item ? <RarityName name={item.name} rarity={item.rarity} className="truncate" /> : <span className="text-ink-600">—</span>}
                                    </li>
                                );
                            })}
                        </ul>
                    </Panel>

                    <Panel title="Respec">
                        <p className="text-sm text-ink-300">
                            Reset every attribute to {baseStat} and get all your points back to spend again.
                        </p>
                        <div className="mt-3 flex items-center justify-between">
                            <span className="text-sm text-ink-400">Cost: <Gold amount={respecCost} /></span>
                            <button
                                className="btn-danger"
                                disabled={c.gold < respecCost}
                                onClick={() => {
                                    if (window.confirm(`Reset your stats for ${respecCost} gold?`)) act('/character/respec', { row_version: c.row_version });
                                }}
                            >
                                <RotateCcw className="size-4" /> Respec
                            </button>
                        </div>
                    </Panel>
                </div>
            </div>
            <p className="mt-6 flex items-center gap-2 text-xs text-ink-500">
                <Shield className="size-3" /> Previews marked ≈ add up per-point gains and can differ slightly near caps.
            </p>
        </>
    );
}

function Slider({ label, value, max, hint, tone, onChange }: {
    label: string;
    value: number;
    max: number;
    hint: string;
    tone: string;
    onChange: (v: number) => void;
}) {
    return (
        <div>
            <div className="flex items-baseline justify-between">
                <span className="label mb-0">{label}</span>
                <span className={clsx('font-display text-2xl font-bold tabular-nums', tone)}>{value}%</span>
            </div>
            <input type="range" min={0} max={max} step={1} value={value} onChange={(e) => onChange(Number(e.target.value))} className="mt-2 w-full" />
            <p className="mt-1 text-xs text-ink-400">{hint}</p>
        </div>
    );
}
