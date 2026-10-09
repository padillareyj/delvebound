import { Head, Link, router, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { Bot, Gem, Shield, Swords, UserRound } from 'lucide-react';
import { useState } from 'react';
import { type HorcruxView } from '@/components/GrowthBoard';
import { GearReadout } from '@/components/ItemCard';
import { ItemGlyph } from '@/components/ItemGlyph';
import SectionNav from '@/components/SectionNav';
import { Bar, Gold, PageHeader, Panel } from '@/components/ui';
import { RANKS_NAV } from '@/lib/nav';
import { DERIVED_LABELS, INJURY_LABELS, RARITY_BORDER, RARITY_TEXT, SLOT_LABELS, STAT_NAMES, derivedDelta, derivedValue, formatDate, useTimezone } from '@/lib/format';
import { Portrait, TitleBadge } from '@/components/Premium';
import type { Appearance, Derived, Item, SharedProps, StatKey, Stats } from '@/types';

interface PublicSkill {
    code: string;
    name: string;
    glyph: string;
    blurb: string;
    rank: number;
    max_rank: number;
    lines: string[];
}

interface Props {
    sort: string;
    character: {
        id: number;
        name: string;
        is_bot: boolean;
        level: number;
        xp: number;
        total_xp: number;
        xp_to_next: number | null;
        gold: number;
        stats: Stats;
        injury_severity: number;
        injury_runs_left: number;
        best_place: string | null;
        created_at: string;
        kills: number;
        boss_kills: number;
        trips_cleared: number;
        defeats: number;
    };
    derived: Required<Derived>;
    baseDerived: Required<Derived>;
    buffs: string[];
    power: number;
    appearance: Appearance;
    skills: PublicSkill[];
    horcrux: HorcruxView;
    equipment: Record<string, Item>;
}

const TABS = [
    { id: 'character', label: 'Character', icon: UserRound },
    { id: 'skills', label: 'Skills', icon: Swords },
    { id: 'horcrux', label: 'Horcrux', icon: Gem },
    { id: 'equipment', label: 'Equipment', icon: Shield },
] as const;

type TabId = (typeof TABS)[number]['id'];

const STATS: StatKey[] = ['str', 'dex', 'int', 'vit', 'luk'];
const SHOWN = ['hp', 'attack', 'defense', 'mres', 'crit', 'crit_mult', 'dodge', 'speed', 'regen', 'luck', 'gold_find'] as const;
const BUFF_LABEL: Record<string, string> = { might: '💪 Might', stoneskin: '🪨 Stoneskin', luck: '🍀 Luck' };
const DOLL: (string | null)[][] = [
    [null, 'head', 'amulet'],
    ['weapon', 'chest', 'offhand'],
    ['ring1', 'hands', 'ring2'],
    [null, 'feet', null],
];

function tabFromUrl(url: string): TabId {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const id = new URLSearchParams(query).get('tab');
    return TABS.some((tab) => tab.id === id) ? (id as TabId) : 'character';
}

export default function Adventurer({
    sort, character: c, derived, baseDerived, buffs, power, appearance, skills, horcrux, equipment,
}: Props) {
    const tz = useTimezone();
    const { hero } = usePage<SharedProps>().props;
    const tab = tabFromUrl(usePage().url);
    const [slot, setSlot] = useState<string | null>(null);
    const yours = hero?.id === c.id;
    const skillRanks = skills.reduce((n, skill) => n + skill.rank, 0);
    const worn = slot ? equipment[slot] : undefined;
    const equipped = Object.keys(equipment).length;

    const openTab = (id: TabId) => {
        const params = new URLSearchParams({ sort });
        if (id !== 'character') params.set('tab', id);
        router.get(`/leaderboard/${c.id}?${params}`, {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title={c.name} />
            <SectionNav entry={RANKS_NAV} />
            <PageHeader
                title={c.name}
                subtitle={<>
                    {appearance.title && <TitleBadge title={appearance.title} className="mr-2 align-middle" />}
                    Level {c.level} · since {formatDate(c.created_at, tz)}{c.best_place && <> · furthest: {c.best_place}</>}
                </>}
            >
                <Link href={`/leaderboard?sort=${sort}`} className="btn-ghost">Back to ranks</Link>
                {yours && <Link href="/character" className="btn-primary">Your sheet</Link>}
            </PageHeader>

            <div className="mb-6 flex flex-wrap items-center gap-2">
                {c.is_bot && <span className="chip border-ink-600 text-ink-300"><Bot className="size-3" /> Rival</span>}
                {yours && <span className="chip border-lantern-400/50 bg-lantern-400/10 text-lantern-200">You</span>}
                <span className="chip border-ink-600 text-ink-200"><Gold amount={c.gold} /></span>
                {buffs.map((b) => (
                    <span key={b} className="chip border-moss-500/40 bg-moss-500/10 text-moss-400">{BUFF_LABEL[b] ?? b}</span>
                ))}
                {c.injury_severity > 0 && (
                    <span className="chip border-blood-500/50 bg-blood-500/10 text-blood-400">
                        🩸 {INJURY_LABELS[c.injury_severity]} ({c.injury_runs_left} runs)
                    </span>
                )}
            </div>

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[13.5rem_1fr]">
                <nav className="flex gap-2 overflow-x-auto lg:sticky lg:top-4 lg:flex-col" aria-label="Adventurer sections">
                    {TABS.map((item) => {
                        const Icon = item.icon;
                        const active = tab === item.id;
                        const note =
                            item.id === 'character' ? `Power ${power}`
                            : item.id === 'skills' ? `${skillRanks} ranks`
                            : item.id === 'horcrux' ? `Tier ${horcrux.tier}/${horcrux.max_tier}`
                            : `${equipped} worn`;
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
                        <Panel title="Attributes" className="xl:col-span-3">
                            <div className="space-y-2">
                                {STATS.map((s) => (
                                    <div key={s} className="flex items-center gap-3 rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2.5">
                                        <div className="w-12 font-display text-sm font-bold text-lantern-300">{STAT_NAMES[s].short}</div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-baseline gap-2">
                                                <span className="text-sm font-semibold text-ink-100">{STAT_NAMES[s].name}</span>
                                                <span className="hidden truncate text-xs text-ink-400 sm:inline">{STAT_NAMES[s].blurb}</span>
                                            </div>
                                        </div>
                                        <span className="text-lg font-bold tabular-nums">{c.stats[s]}</span>
                                    </div>
                                ))}
                            </div>
                        </Panel>
                        <div className="space-y-6 xl:col-span-2">
                            <Panel title="Power">
                                {appearance.gear.length > 0 && <Portrait look={appearance} label={c.level} className="mx-auto mb-2" />}
                                <div className="font-display text-4xl font-bold tabular-nums text-lantern-200">{power}</div>
                                <p className="mt-1 text-xs text-ink-400">
                                    Level {c.level}, {skillRanks} skill ranks, horcrux tier {horcrux.tier}.
                                </p>
                                <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
                                    <Stat label="Kills" value={c.kills} />
                                    <Stat label="Bosses" value={c.boss_kills} />
                                    <Stat label="Clears" value={c.trips_cleared} />
                                    <Stat label="Defeats" value={c.defeats} />
                                </dl>
                            </Panel>
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
                                        return (
                                            <div key={k} className="flex items-center justify-between py-1.5">
                                                <dt className="text-ink-300">{DERIVED_LABELS[k]}</dt>
                                                <dd className="flex items-center gap-2 tabular-nums">
                                                    {Math.abs(buffed) > 0.001 && <span className="text-[11px] text-moss-400">({derivedDelta(k, buffed)} buff)</span>}
                                                    <span className="font-semibold text-ink-100">{derivedValue(k, derived[k])}</span>
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
                        </div>
                    </div>
                )}

                {tab === 'skills' && (
                    <Panel title="Skills">
                        <ul className="space-y-3">
                            {skills.map((skill) => (
                                <li key={skill.code} className="rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="font-semibold text-ink-100">{skill.glyph} {skill.name}</div>
                                            <div className="text-[11px] text-ink-400">{skill.blurb}</div>
                                            <div className="mt-1 flex gap-1">
                                                {Array.from({ length: skill.max_rank }, (_, i) => (
                                                    <span key={i} className={clsx('size-2 rounded-full', i < skill.rank ? 'bg-lantern-400' : 'bg-ink-700')} />
                                                ))}
                                            </div>
                                            <div className="mt-1 text-[11px] text-moss-400">{skill.lines.join(' · ')}</div>
                                        </div>
                                        <span className="chip border-ink-600 text-ink-200">Rank {skill.rank}/{skill.max_rank}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                {tab === 'horcrux' && <HorcruxReadout horcrux={horcrux} />}

                {tab === 'equipment' && (
                    <div className="grid grid-cols-1 gap-6 xl:grid-cols-[16rem_1fr]">
                        <Panel title="Equipped">
                            <div className="grid grid-cols-3 gap-1.5">
                                {DOLL.flat().map((name, i) =>
                                    name === null ? (
                                        <div key={i} />
                                    ) : (
                                        <EquipSlot
                                            key={name}
                                            name={name}
                                            item={equipment[name]}
                                            active={slot === name}
                                            onClick={() => setSlot(slot === name ? null : name)}
                                        />
                                    ),
                                )}
                            </div>
                            <p className="mt-2 text-[11px] text-ink-500">Hover a piece for its stats, or click it to pin them.</p>
                        </Panel>
                        <Panel title={worn ? worn.name : 'Gear'}>
                            {worn ? <GearReadout item={worn} /> : (
                                <p className="text-sm text-ink-400">
                                    {equipped === 0 ? 'Nothing is equipped.' : 'Select a worn piece to read its stats.'}
                                </p>
                            )}
                        </Panel>
                    </div>
                )}
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2">
            <dt className="text-[11px] uppercase tracking-wider text-ink-400">{label}</dt>
            <dd className="font-bold tabular-nums text-ink-100">{value}</dd>
        </div>
    );
}

function HorcruxReadout({ horcrux }: { horcrux: HorcruxView }) {
    const per = [
        horcrux.per_tier.hp ? `+${horcrux.per_tier.hp} HP` : null,
        horcrux.per_tier.attack ? `+${horcrux.per_tier.attack} Attack` : null,
        horcrux.per_tier.defense ? `+${horcrux.per_tier.defense} Defense` : null,
    ].filter(Boolean).join(', ');
    const width = horcrux.next_at ? Math.min(100, (horcrux.progress / horcrux.next_at) * 100) : 100;

    return (
        <Panel title={horcrux.name} action={<span className="text-xs text-ink-400">Tier {horcrux.tier}/{horcrux.max_tier}</span>}>
            <p className="text-xs text-ink-400">Each tier grants {per}.</p>
            <div className="mt-3">
                <div className="mb-1 flex justify-between text-[11px] text-ink-400">
                    <span>{horcrux.progress} fed</span>
                    <span>{horcrux.next_at === null ? 'Full' : `${horcrux.next_at} to next tier`}</span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-ink-800">
                    <div className="h-full bg-arcane-400" style={{ width: `${width}%` }} />
                </div>
            </div>
        </Panel>
    );
}

function EquipSlot({ name, item, active, onClick }: { name: string; item?: Item; active: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={item ? item.name : SLOT_LABELS[name] ?? name}
            className={clsx(
                'group relative grid aspect-square place-items-center rounded-md border bg-ink-950/80 text-xl',
                item ? RARITY_BORDER[item.rarity] : 'border-dashed border-ink-700',
                item ? RARITY_TEXT[item.rarity] : 'text-ink-500',
                active && 'ring-2 ring-lantern-400',
            )}
        >
            <ItemGlyph slot={item?.template_slot ?? name} itemType={item?.item_type} twoHanded={item?.two_handed} className={clsx('size-7', !item && 'opacity-40')} />
            {item?.two_handed && <span className="absolute right-0.5 bottom-0.5 rounded bg-ink-950/90 px-0.5 text-[8px] font-bold leading-none text-lantern-200">2H</span>}
            {item && (
                <div className="pointer-events-none absolute bottom-[calc(100%+6px)] left-1/2 z-30 hidden w-56 -translate-x-1/2 rounded-lg border border-ink-600 bg-ink-950 p-2 text-left shadow-xl group-hover:block">
                    <GearReadout item={item} />
                </div>
            )}
        </button>
    );
}
