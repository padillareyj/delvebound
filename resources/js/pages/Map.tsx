import { Head } from '@inertiajs/react';
import clsx from 'clsx';
import { Compass, Crown, Lock, Skull, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
import { PageHeader, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { PLACE_ICONS } from '@/lib/format';
import type { NextDestination, Order } from '@/types';

interface Place {
    id: number;
    code: string;
    name: string;
    place_type: string;
    description: string | null;
    min_level: number;
    max_level: number | null;
    rec_min_level: number;
    rec_max_level: number;
    rec_level: number;
    encounter_min: number;
    encounter_max: number;
    boss: { name: string; level: number; chance: number } | null;
    gold_mult: number;
    rarity_bonus: number;
    region_code: string;
    map_x: number;
    map_y: number;
    open: boolean;
    locked_reason: string | null;
    warning: string | null;
    monsters: { name: string; level: number; damage_type: string }[];
    history: { trips: number; clears: number; defeats: number };
}

interface Region {
    code: string;
    name: string;
    blurb: string | null;
}

interface Props {
    level: number;
    regions: Region[];
    places: Place[];
    order: Order | null;
    next: NextDestination;
    lastPlaceId: number | null;
    autoRepeat: boolean;
    injury: number;
}

function nearestLower(p: Place, all: Place[]): Place | undefined {
    const dist = (a: Place) => Math.hypot(a.map_x - p.map_x, a.map_y - p.map_y);
    return all.filter((a) => a.min_level < p.min_level || (a.place_type === 'rest' && a.id !== p.id)).sort((a, b) => dist(a) - dist(b))[0];
}

function fit(level: number, p: Place): 'rest' | 'locked' | 'hard' | 'good' | 'easy' {
    if (p.place_type === 'rest') return 'rest';
    if (!p.open) return 'locked';
    if (level < p.rec_min_level) return 'hard';
    if (level > p.rec_max_level) return 'easy';
    return 'good';
}

const FIT_STYLE = {
    rest: { ring: 'ring-frost-400/70', bg: 'from-sky-900 to-ink-900', label: 'Safe haven', text: 'text-frost-400' },
    locked: { ring: 'ring-ink-600', bg: 'from-ink-800 to-ink-950', label: 'Locked', text: 'text-ink-400' },
    hard: { ring: 'ring-blood-500/80', bg: 'from-blood-600/40 to-ink-900', label: 'Dangerous', text: 'text-blood-400' },
    good: { ring: 'ring-moss-400/80', bg: 'from-moss-500/30 to-ink-900', label: 'Good match', text: 'text-moss-400' },
    easy: { ring: 'ring-ink-400', bg: 'from-ink-700 to-ink-900', label: 'Too easy', text: 'text-ink-300' },
};

const PARCHMENT: Record<string, string> = {
    wilds: 'parchment',
    salt_marches: 'parchment parchment-salt',
    ashen_crown: 'parchment parchment-ash',
};

export default function MapPage({ level, regions, places, order, next, lastPlaceId }: Props) {
    const initial = places.find((p) => p.id === order?.place_id) ?? places.find((p) => p.id === lastPlaceId) ?? places[0];
    const [regionCode, setRegionCode] = useState(initial?.region_code ?? regions[0]?.code);
    const [selectedId, setSelectedId] = useState<number | undefined>(initial?.id);
    const region = regions.find((r) => r.code === regionCode) ?? regions[0];
    const shown = places.filter((p) => p.region_code === region?.code);
    const selected = places.find((p) => p.id === selectedId);
    const anchor = shown.find((p) => p.place_type === 'rest') ?? [...shown].sort((a, b) => a.min_level - b.min_level)[0];
    const trail = [...shown].sort((a, b) => a.min_level - b.min_level);

    function openRegion(code: string) {
        setRegionCode(code);
        setSelectedId((prev) => {
            const current = places.find((p) => p.id === prev);
            if (current?.region_code === code) return prev;
            return places.find((p) => p.region_code === code)?.id;
        });
    }

    return (
        <>
            <Head title="Map" />
            <PageHeader
                title={region?.name ?? 'Map'}
                subtitle={<>{region?.blurb} Next run: <span className="font-semibold text-lantern-200">{next.label}</span>.</>}
            >
                {order && (
                    <button className="btn-ghost" onClick={() => act('/map/order', {}, { method: 'delete' })}>
                        <X className="size-4" /> Cancel order
                    </button>
                )}
            </PageHeader>

            <div className="mb-4 flex flex-wrap gap-2">
                {regions.map((r) => (
                    <button
                        key={r.code}
                        type="button"
                        onClick={() => openRegion(r.code)}
                        className={clsx(
                            'rounded-full px-4 py-1.5 text-sm font-semibold transition',
                            r.code === region?.code
                                ? 'bg-lantern-400 text-ink-950'
                                : 'bg-ink-800 text-ink-200 ring-1 ring-ink-600 hover:bg-ink-700',
                        )}
                    >
                        {r.name}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
                <div className={clsx(PARCHMENT[region?.code ?? 'wilds'] ?? 'parchment', 'relative aspect-[4/3] w-full overflow-hidden rounded-2xl border border-ink-700 shadow-2xl sm:aspect-[16/10]')}>
                    <svg className="absolute inset-0 size-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                        <defs>
                            <pattern id="grid" width="5" height="5" patternUnits="userSpaceOnUse">
                                <path d="M5 0H0V5" fill="none" stroke="rgb(138 149 179 / 0.06)" strokeWidth="0.15" />
                            </pattern>
                        </defs>
                        <rect width="100" height="100" fill="url(#grid)" />
                        {anchor &&
                            trail
                                .filter((p) => p.id !== anchor.id)
                                .map((p, i) => {
                                    const from = nearestLower(p, trail) ?? anchor;
                                    const mx = (from.map_x + p.map_x) / 2 + (i % 2 ? 5 : -5);
                                    const my = (from.map_y + p.map_y) / 2;
                                    return (
                                        <path
                                            key={p.id}
                                            d={`M${from.map_x} ${from.map_y} Q${mx} ${my} ${p.map_x} ${p.map_y}`}
                                            fill="none"
                                            stroke={p.open ? 'rgb(245 180 83 / 0.45)' : 'rgb(91 103 136 / 0.35)'}
                                            strokeWidth="0.45"
                                            strokeDasharray="1.4 1.1"
                                            vectorEffect="non-scaling-stroke"
                                            style={{ strokeWidth: 2 }}
                                        />
                                    );
                                })}
                    </svg>

                    <div className="pointer-events-none absolute top-3 left-4 font-display text-xs tracking-[0.3em] text-ink-400/70 uppercase">{region?.name}</div>
                    <Compass className="pointer-events-none absolute right-4 bottom-4 size-10 text-ink-500/40" />

                    {shown.map((p) => {
                        const f = fit(level, p);
                        const style = FIT_STYLE[f];
                        const isOrder = order?.place_id === p.id;
                        const isSelected = selectedId === p.id;
                        return (
                            <button
                                key={p.id}
                                onClick={() => setSelectedId(p.id)}
                                className="group absolute -translate-x-1/2 -translate-y-1/2 focus:outline-none"
                                style={{ left: `${p.map_x}%`, top: `${p.map_y}%` }}
                            >
                                {isOrder && <span className="absolute inset-0 animate-pulse-ring rounded-full bg-lantern-400/60" />}
                                <span
                                    className={clsx(
                                        'relative grid size-12 place-items-center rounded-full bg-gradient-to-b text-2xl ring-2 transition sm:size-14 sm:text-3xl',
                                        style.bg,
                                        isOrder ? 'ring-lantern-400' : style.ring,
                                        isSelected && 'scale-110 shadow-[0_0_30px_-4px_var(--color-lantern-400)]',
                                        f === 'locked' && 'grayscale',
                                        'group-hover:scale-110',
                                    )}
                                >
                                    {PLACE_ICONS[p.place_type] ?? '📍'}
                                    {f === 'locked' && (
                                        <span className="absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full bg-ink-800 ring-1 ring-ink-600">
                                            <Lock className="size-3 text-ink-300" />
                                        </span>
                                    )}
                                    {p.boss && f !== 'locked' && (
                                        <span className="absolute -top-1 -right-1 grid size-5 place-items-center rounded-full bg-blood-600 ring-1 ring-blood-400">
                                            <Skull className="size-3 text-white" />
                                        </span>
                                    )}
                                </span>
                                <span
                                    className={clsx(
                                        'absolute top-full left-1/2 mt-1.5 -translate-x-1/2 rounded-md bg-ink-950/80 px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap backdrop-blur',
                                        isSelected ? 'text-lantern-200' : 'text-ink-200',
                                    )}
                                >
                                    {p.name}
                                    {p.place_type !== 'rest' && <span className={clsx('ml-1', style.text)}>Lv {p.rec_min_level}–{p.rec_max_level}</span>}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div>
                    {selected ? (
                        <PlaceDetails key={selected.id} place={selected} level={level} ordered={order?.place_id === selected.id} />
                    ) : (
                        <Panel title="Select a place"><p className="text-sm text-ink-400">Click a pin on the map.</p></Panel>
                    )}
                    <div className="mt-4 flex flex-wrap gap-3 text-[11px] text-ink-400">
                        {(['good', 'hard', 'easy', 'locked'] as const).map((f) => (
                            <span key={f} className="flex items-center gap-1.5">
                                <span className={clsx('size-2.5 rounded-full ring-2', FIT_STYLE[f].ring)} /> {FIT_STYLE[f].label}
                            </span>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

function PlaceDetails({ place: p, level, ordered }: { place: Place; level: number; ordered: boolean }) {
    const f = fit(level, p);
    const style = FIT_STYLE[f];
    const winRate = p.history.trips ? Math.round((p.history.clears / p.history.trips) * 100) : null;

    return (
        <Panel
            title={<span className="flex items-center gap-2"><span className="text-xl">{PLACE_ICONS[p.place_type]}</span>{p.name}</span>}
            action={<span className={clsx('chip border-current/40', style.text)}>{style.label}</span>}
        >
            {p.description && <p className="text-sm leading-relaxed text-ink-300 italic">{p.description}</p>}

            {p.place_type !== 'rest' && (
                <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                    <div className="rounded-lg bg-ink-900/60 p-2">
                        <div className="text-ink-400">Level</div>
                        <div className="font-bold text-ink-100">{p.rec_min_level}–{p.rec_max_level}</div>
                    </div>
                    <div className="rounded-lg bg-ink-900/60 p-2">
                        <div className="text-ink-400">Fights</div>
                        <div className="font-bold text-ink-100">{p.encounter_min}–{p.encounter_max}</div>
                    </div>
                    <div className="rounded-lg bg-ink-900/60 p-2">
                        <div className="text-ink-400">Gold</div>
                        <div className="font-bold text-lantern-300">×{p.gold_mult.toFixed(1)}</div>
                    </div>
                </div>
            )}

            {p.locked_reason && (
                <p className="mt-4 flex items-start gap-2 rounded-lg border border-ink-600 bg-ink-900/60 p-3 text-sm text-ink-300">
                    <Lock className="mt-0.5 size-4 shrink-0" /> {p.locked_reason}
                </p>
            )}
            {p.warning && (
                <p className="mt-4 flex items-start gap-2 rounded-lg border border-lantern-500/40 bg-lantern-500/10 p-3 text-sm text-lantern-200">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {p.warning}
                </p>
            )}

            {p.monsters.length > 0 && (
                <div className="mt-4">
                    <div className="label">Denizens</div>
                    <ul className="space-y-1 text-sm">
                        {p.monsters.map((m) => (
                            <li key={m.name} className="flex items-center justify-between">
                                <span className={clsx(p.boss?.name === m.name ? 'font-semibold text-blood-400' : 'text-ink-200')}>
                                    {p.boss?.name === m.name && <Crown className="mr-1 inline size-3.5" />}
                                    {m.name}
                                </span>
                                <span className="text-xs text-ink-400">
                                    Lv {m.level}
                                    {m.damage_type === 'magic' && <span className="ml-1 text-arcane-400">✦ magic</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {p.boss && (
                        <p className="mt-2 text-xs text-ink-400">
                            {Math.round(p.boss.chance * 100)}% chance the {p.boss.name} guards the end.
                        </p>
                    )}
                </div>
            )}

            {p.history.trips > 0 && (
                <div className="mt-4 rounded-lg bg-ink-900/60 p-3 text-xs text-ink-300">
                    Your record: {p.history.trips} trips, {p.history.clears} cleared, {p.history.defeats} defeats
                    {winRate !== null && <span className="ml-1 text-ink-400">({winRate}% clear rate)</span>}
                </div>
            )}

            <div className="mt-5">
                {ordered ? (
                    <div className="flex items-center justify-between rounded-lg border border-lantern-400/50 bg-lantern-400/10 p-3 text-sm text-lantern-200">
                        <span className="flex items-center gap-2"><Compass className="size-4" /> Ordered for the next run</span>
                        <button className="link text-xs" onClick={() => act('/map/order', {}, { method: 'delete' })}>Cancel</button>
                    </div>
                ) : (
                    <button className="btn-primary w-full py-2.5" disabled={!p.open} onClick={() => act('/map/order', { place_id: p.id })}>
                        {p.place_type === 'rest' ? 'Rest here next run' : p.open ? 'Set out on the next run' : 'Locked'}
                    </button>
                )}
            </div>
        </Panel>
    );
}
