import { Head, Link, router, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { Backpack, Bot, Crown, Gem, Hammer, Minus, Plus, RotateCcw, Shield, Sparkles, Swords, UserRound } from 'lucide-react';
import { useMemo, useState } from 'react';
import { HorcruxBoard, MaterialsBoard, SkillsBoard, type HorcruxView, type MaterialView, type SkillView } from '@/components/GrowthBoard';
import CollectionBoard from '@/components/CollectionBoard';
import ForgeBoard from '@/components/ForgeBoard';
import InventoryBoard from '@/components/InventoryBoard';
import { Portrait, TitleBadge } from '@/components/Premium';
import { Bar, Gold, PageHeader, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { confirmDialog } from '@/lib/dialog';
import {
    BONUS_LABELS, BONUS_ORDER, DERIVED_LABELS, INJURY_LABELS, STAT_NAMES, bonusLine, derivedDelta, derivedValue, formatDate, money, useTimezone,
} from '@/lib/format';
import type { Appearance, Collection, Consumable, Derived, ForgeQuote, Item, PackItem, SealedChest, StatKey, Stats } from '@/types';

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
        auto_mode: boolean;
        auto_place: 'profile' | 'best';
        auto_profile: string | null;
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
    autoProfiles: { code: string; name: string; archetype: string; description: string }[];
    power: number;
    powerParts: { level: number; growth: number; premium: number };
    plainDerived: Required<Derived>;
    bonusSources: { source: string; bonus: Record<string, number> }[];
    appearance: Appearance;
    cash: number;
    collection: Collection;
    skills: SkillView[];
    horcrux: HorcruxView;
    materials: MaterialView[];
    rowVersion: number;
    injury: number;
    equipment: Record<string, Item>;
    inventory: PackItem[];
    consumables: Consumable[];
    chests: SealedChest[];
    capacity: number;
    beltSlots: number;
    forgeQuote: ForgeQuote | null;
}

const TABS = [
    { id: 'character', label: 'Character', icon: UserRound },
    { id: 'skills', label: 'Skills', icon: Swords },
    { id: 'horcrux', label: 'Horcrux', icon: Gem },
    { id: 'inventory', label: 'Inventory', icon: Backpack },
    { id: 'forge', label: 'Forge', icon: Hammer },
    { id: 'collection', label: 'Collection', icon: Crown },
] as const;

type TabId = (typeof TABS)[number]['id'];

function tabFromUrl(url: string): TabId {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const id = new URLSearchParams(query).get('tab');
    return TABS.some((tab) => tab.id === id) ? (id as TabId) : 'character';
}

const STATS: StatKey[] = ['str', 'dex', 'int', 'vit', 'luk'];
const SHOWN = ['hp', 'attack', 'defense', 'mres', 'crit', 'crit_mult', 'dodge', 'speed', 'regen', 'luck', 'gold_find', 'lifesteal', 'penetration', 'dmg_reduction', 'xp_mult'] as const;
const BONUS_ROWS = ['hp', 'attack', 'defense', 'mres', 'crit', 'crit_mult', 'dodge', 'speed', 'luck', 'gold_find', 'lifesteal', 'penetration', 'dmg_reduction', 'xp_mult'] as const;
const EMPTY: Stats = { str: 0, dex: 0, int: 0, vit: 0, luk: 0 };
const BUFF_LABEL: Record<string, string> = { might: '💪 Might', stoneskin: '🪨 Stoneskin', luck: '🍀 Luck' };

export default function Character({
    character: c, derived, baseDerived, buffs, perPoint, respecCost, pointPool, baseStat, power,
    skills, horcrux, materials, rowVersion, injury, equipment, inventory, consumables, chests, capacity, beltSlots,
    forgeQuote,
    autoProfiles, powerParts, plainDerived, bonusSources, appearance, cash, collection,
}: Props) {
    const tz = useTimezone();
    const tab = tabFromUrl(usePage().url);
    const [alloc, setAlloc] = useState<Stats>(EMPTY);
    const [saving, setSaving] = useState(false);
    const [prep, setPrep] = useState({
        retreat_threshold_pct: c.retreat_threshold_pct,
        potion_threshold_pct: c.potion_threshold_pct,
        auto_repeat: c.auto_repeat,
        auto_mode: c.auto_mode,
        auto_place: c.auto_place ?? 'profile',
        auto_profile: c.auto_profile ?? 'ranger',
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
        prep.auto_repeat !== c.auto_repeat ||
        prep.auto_mode !== c.auto_mode ||
        prep.auto_place !== (c.auto_place ?? 'profile') ||
        prep.auto_profile !== (c.auto_profile ?? 'ranger');

    const openTab = (id: TabId) => {
        router.get(id === 'character' ? '/character' : `/character?tab=${id}`, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const skillRanks = skills.reduce((n, skill) => n + skill.rank, 0);
    const lootStacks = materials.filter((mat) => mat.qty > 0).length;
    const ownedPieces = collection.pieces.filter((p) => p.owned);

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
                subtitle={<>
                    {appearance.title && <><TitleBadge title={appearance.title} className="mr-2 align-middle" /></>}
                    Level {c.level} · since {formatDate(c.created_at, tz)}{c.best_place && <> · furthest: {c.best_place}</>}
                </>}
            >
                {buffs.map((b) => (
                    <span key={b} className="chip border-moss-500/40 bg-moss-500/10 text-moss-400">{BUFF_LABEL[b] ?? b}</span>
                ))}
                {c.auto_mode && (
                    <span className="chip border-arcane-400/50 bg-arcane-500/10 text-arcane-400">
                        <Bot className="size-3" /> Auto mode
                    </span>
                )}
                {c.injury_severity > 0 && (
                    <span className="chip border-blood-500/50 bg-blood-500/10 text-blood-400">
                        🩸 {INJURY_LABELS[c.injury_severity]} ({c.injury_runs_left} runs)
                    </span>
                )}
            </PageHeader>

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[13.5rem_1fr]">
                <nav className="flex gap-2 overflow-x-auto lg:sticky lg:top-4 lg:flex-col" aria-label="Hero sections">
                    {TABS.map((item) => {
                        const Icon = item.icon;
                        const active = tab === item.id;
                        const note =
                            item.id === 'character' ? `Power ${power}`
                            : item.id === 'skills' ? `${skillRanks} ranks`
                            : item.id === 'horcrux' ? `Tier ${horcrux.tier}/${horcrux.max_tier}`
                            : item.id === 'forge' ? 'Combine two pieces'
                            : item.id === 'collection' ? `${ownedPieces.length} owned · ${ownedPieces.filter((p) => p.active).length} active`
                            : `${inventory.length} gear · ${lootStacks} loot`;
                        return (
                            <button
                                key={item.id}
                                type="button"
                                aria-current={active ? 'page' : undefined}
                                onClick={() => openTab(item.id)}
                                className={clsx(
                                    'flex min-w-36 items-center gap-3 rounded-lg border px-3 py-2.5 text-left transition lg:w-full lg:min-w-0',
                                    active
                                        ? 'border-lantern-400/50 bg-lantern-400/10 text-lantern-100'
                                        : 'border-ink-700/80 bg-ink-900/40 text-ink-300 hover:border-ink-500 hover:bg-ink-800/60',
                                )}
                            >
                                <Icon className={clsx('size-4 shrink-0', active ? 'text-lantern-300' : 'text-ink-400')} />
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold">{item.label}</span>
                                    <span className="block truncate text-[11px] text-ink-400">{note}</span>
                                </span>
                            </button>
                        );
                    })}
                </nav>

                {tab === 'character' && (
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="space-y-6 xl:col-span-3">
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
                        <div className="rounded-lg border border-ink-700 bg-ink-900/40 px-4 py-3">
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <div className="text-sm font-semibold text-ink-100">Auto mode</div>
                                    <div className="text-xs text-ink-400">At the start of each run, spend points, equip, shop, and choose the destination.</div>
                                </div>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={prep.auto_mode}
                                    aria-label="Auto mode"
                                    onClick={() => setPrep((p) => ({ ...p, auto_mode: !p.auto_mode }))}
                                    className={clsx('relative h-6 w-11 shrink-0 rounded-full transition', prep.auto_mode ? 'bg-arcane-500' : 'bg-ink-600')}
                                >
                                    <span className={clsx('absolute top-0.5 size-5 rounded-full bg-white shadow transition', prep.auto_mode ? 'left-5.5' : 'left-0.5')} />
                                </button>
                            </div>
                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                {autoProfiles.map((profile) => (
                                    <button
                                        key={profile.code}
                                        type="button"
                                        onClick={() => setPrep((p) => ({ ...p, auto_profile: profile.code }))}
                                        className={clsx(
                                            'rounded-lg border px-3 py-2 text-left transition',
                                            prep.auto_profile === profile.code
                                                ? 'border-arcane-400/60 bg-arcane-500/10'
                                                : 'border-ink-700 hover:border-ink-500',
                                        )}
                                    >
                                        <div className="text-sm font-semibold text-ink-100">{profile.name}</div>
                                        <div className="text-[11px] uppercase tracking-wider text-ink-500">{profile.archetype}</div>
                                        <p className="mt-1 text-xs text-ink-400">{profile.description}</p>
                                    </button>
                                ))}
                            </div>
                            {prep.auto_mode && prep.auto_place === 'profile' && (
                                <p className="mt-3 text-[11px] text-lantern-300">
                                    A manual order, gear change, or unspent points can be overwritten when the run starts. Every point is spent. The personality also sets when you retreat and drink.
                                </p>
                            )}
                            <div className="mt-4 border-t border-ink-700 pt-3">
                                <div className="text-sm font-semibold text-ink-100">Map</div>
                                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                    <button
                                        type="button"
                                        onClick={() => setPrep((p) => ({ ...p, auto_place: 'profile' }))}
                                        className={clsx(
                                            'rounded-lg border px-3 py-2 text-left transition',
                                            prep.auto_place === 'profile'
                                                ? 'border-arcane-400/60 bg-arcane-500/10'
                                                : 'border-ink-700 hover:border-ink-500',
                                        )}
                                    >
                                        <div className="text-sm font-semibold text-ink-100">Follow the personality</div>
                                        <p className="mt-1 text-xs text-ink-400">The rival&apos;s appetite for risk picks the place, the same way the rivals do.</p>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setPrep((p) => ({ ...p, auto_place: 'best' }))}
                                        className={clsx(
                                            'rounded-lg border px-3 py-2 text-left transition',
                                            prep.auto_place === 'best'
                                                ? 'border-arcane-400/60 bg-arcane-500/10'
                                                : 'border-ink-700 hover:border-ink-500',
                                        )}
                                    >
                                        <div className="text-sm font-semibold text-ink-100">Best map for my stats</div>
                                        <p className="mt-1 text-xs text-ink-400">The hardest open place you can clear. A road that looks like a defeat is skipped, and you rest in town if every road would.</p>
                                    </button>
                                </div>
                            </div>
                            {prep.auto_mode && prep.auto_place === 'best' && (
                                <p className="mt-3 text-[11px] text-lantern-300">
                                    Every unspent point is spent, then the best gear is worn. Shopping still follows the personality. The map is checked against those stats, your injury, and the potions you already own. A place that looks like a defeat is skipped. If every open place would defeat you, you rest in town.
                                </p>
                            )}
                        </div>
                        <div className="mt-5 grid gap-5 sm:grid-cols-2">
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
                                onClick={() => act('/character/prep', {
                                    ...prep,
                                    auto_repeat: prep.auto_repeat ? 1 : 0,
                                    auto_mode: prep.auto_mode ? 1 : 0,
                                    auto_place: prep.auto_place,
                                    row_version: c.row_version,
                                })}
                            >
                                Save orders
                            </button>
                        </div>
                    </Panel>
                </div>

                <div className="space-y-6 xl:col-span-2">
                    <Panel
                        title="Combat power"
                        action={
                            <Link href="/premium" className="chip border-moss-500/40 bg-moss-500/10 text-moss-400 hover:border-moss-400">
                                <Gem className="size-3" /> {money(cash)}
                            </Link>
                        }
                    >
                        <div className="flex items-center gap-4">
                            <Portrait look={appearance} label={c.level} size="sm" className="!h-16 !w-24" />
                            <div className="min-w-0">
                                <div className="font-display text-4xl font-bold tabular-nums text-lantern-200">{power}</div>
                                <TitleBadge title={appearance.title} />
                            </div>
                        </div>
                        <div className="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                            <PowerPart label="Level" value={powerParts.level} />
                            <PowerPart label={`${skillRanks} ranks · tier ${horcrux.tier}`} value={powerParts.growth} />
                            <PowerPart label="Premium" value={powerParts.premium} accent="text-moss-400" />
                        </div>
                    </Panel>
                    <BonusPanel derived={baseDerived} plain={plainDerived} sources={bonusSources} />
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

                    <Panel title="Respec">
                        <p className="text-sm text-ink-300">
                            Reset every attribute to {baseStat} and get all your points back to spend again.
                        </p>
                        <div className="mt-3 flex items-center justify-between">
                            <span className="text-sm text-ink-400">Cost: <Gold amount={respecCost} /></span>
                            <button
                                className="btn-danger"
                                disabled={c.gold < respecCost}
                                onClick={async () => {
                                    const ok = await confirmDialog({
                                        tone: 'danger',
                                        icon: <RotateCcw className="size-8" />,
                                        title: 'Reset your stats?',
                                        body: <span className="flex items-center justify-center gap-1">Every point comes back to spend again. Cost <Gold amount={respecCost} /></span>,
                                        confirmLabel: 'Respec',
                                    });
                                    if (ok) act('/character/respec', { row_version: c.row_version });
                                }}
                            >
                                <RotateCcw className="size-4" /> Respec
                            </button>
                        </div>
                    </Panel>
                </div>
                </div>
                )}

                {tab === 'skills' && (
                    <SkillsBoard skills={skills} rowVersion={rowVersion} />
                )}

                {tab === 'horcrux' && (
                    <HorcruxBoard horcrux={horcrux} />
                )}

                {tab === 'inventory' && (
                    <div className="space-y-6">
                        <InventoryBoard
                            rowVersion={rowVersion}
                            injury={injury}
                            equipment={equipment}
                            inventory={inventory}
                            consumables={consumables}
                            chests={chests}
                            capacity={capacity}
                            beltSlots={beltSlots}
                            horcruxFull={horcrux.tier >= horcrux.max_tier}
                        />
                        <MaterialsBoard materials={materials} />
                    </div>
                )}

                {tab === 'forge' && (
                    <ForgeBoard rowVersion={rowVersion} gold={c.gold} inventory={inventory} forgeQuote={forgeQuote} />
                )}

                {tab === 'collection' && (
                    <CollectionBoard collection={collection} appearance={appearance} level={c.level} name={c.name} cash={cash} rowVersion={rowVersion} />
                )}
            </div>
            <p className="mt-6 flex items-center gap-2 text-xs text-ink-500">
                <Shield className="size-3" /> Previews marked ≈ add up per-point gains and can differ slightly near caps.
            </p>
        </>
    );
}

function PowerPart({ label, value, accent }: { label: string; value: number; accent?: string }) {
    return (
        <div className="rounded-lg border border-ink-700/70 bg-ink-900/50 px-2 py-1.5">
            <div className={clsx('font-bold tabular-nums', accent ?? 'text-ink-100')}>+{value}</div>
            <div className="truncate text-[10px] text-ink-400">{label}</div>
        </div>
    );
}

/** Base (attributes + gear) → bonus (skills, horcrux, premium) → total, the way mobile RPG sheets show it. */
function BonusPanel({ derived, plain, sources }: {
    derived: Required<Derived>;
    plain: Required<Derived>;
    sources: { source: string; bonus: Record<string, number> }[];
}) {
    const keys = BONUS_ORDER.filter((key) => sources.some((s) => s.bonus[key]));
    return (
        <Panel title={<><Sparkles className="mr-1 inline size-4" /> Stat bonuses</>}>
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-[10px] uppercase tracking-wider text-ink-500">
                        <th className="pb-1 text-left font-semibold">Stat</th>
                        <th className="pb-1 text-right font-semibold">Base</th>
                        <th className="pb-1 text-right font-semibold">Bonus</th>
                        <th className="pb-1 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-ink-700/40">
                    {BONUS_ROWS.map((k) => {
                        const extra = derived[k] - plain[k];
                        return (
                            <tr key={k}>
                                <td className="py-1 text-ink-300">{DERIVED_LABELS[k]}</td>
                                <td className="py-1 text-right text-ink-400 tabular-nums">{derivedValue(k, plain[k])}</td>
                                <td className={clsx('py-1 text-right tabular-nums', Math.abs(extra) > 0.0001 ? 'font-semibold text-moss-400' : 'text-ink-600')}>
                                    {Math.abs(extra) > 0.0001 ? derivedDelta(k, extra) : '—'}
                                </td>
                                <td className="py-1 text-right font-semibold text-ink-100 tabular-nums">{derivedValue(k, derived[k])}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            <div className="mt-4 space-y-2">
                {keys.length === 0 && (
                    <p className="text-xs text-ink-400">
                        Train skills, feed the horcrux, or wear pieces from the <Link href="/premium" className="text-moss-400 underline">premium store</Link> to add bonuses.
                    </p>
                )}
                {sources.map((s) => {
                    const lines = BONUS_ORDER.filter((key) => s.bonus[key]);
                    if (lines.length === 0) return null;
                    return (
                        <div key={s.source} className="rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2">
                            <div className="text-[11px] font-semibold uppercase tracking-wider text-ink-400">{s.source}</div>
                            <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-moss-400">
                                {lines.map((key) => (
                                    <span key={key} title={BONUS_LABELS[key]}>{bonusLine(key, s.bonus[key])}</span>
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>
        </Panel>
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
