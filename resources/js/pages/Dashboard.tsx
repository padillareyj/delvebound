import { Head, Link, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { ArrowRight, Bot, Compass, Flame, Mail, Repeat, Skull, Sparkles, Swords, Tent } from 'lucide-react';
import { ItemGlyph } from '@/components/ItemGlyph';
import { Bar, Empty, Gold, OutcomeChip, Panel, StatTile } from '@/components/ui';
import { DERIVED_LABELS, PLACE_ICONS, RARITY_BORDER, RARITY_TEXT, SLOT_LABELS, derivedValue, formatDateTime, timeAgo, useTimezone } from '@/lib/format';
import type { Consumable, Derived, Item, MailRow, NextDestination, Order, SharedProps, TripRow } from '@/types';

interface Props {
    next: NextDestination;
    order: Order | null;
    autoRepeat: boolean;
    belt: Consumable[];
    beltSlots: number;
    derived: Derived;
    equipment: Record<string, Item>;
    recentTrips: TripRow[];
    recentMail: MailRow[];
    counters: { trips: number; cleared: number; retreated: number; defeats: number; kills: number; boss_kills: number };
}

const NEXT_META: Record<NextDestination['kind'], { icon: typeof Compass; tone: string; note: string }> = {
    order: { icon: Compass, tone: 'text-lantern-300', note: 'Your order is on the board for the next run.' },
    repeat: { icon: Repeat, tone: 'text-arcane-400', note: 'Auto-repeat will send you back to your last destination.' },
    rest: { icon: Tent, tone: 'text-frost-400', note: 'No order placed, so you will rest in town.' },
    blocked: { icon: Flame, tone: 'text-blood-400', note: 'You are badly wounded and must rest in town for one run.' },
    auto: { icon: Bot, tone: 'text-arcane-400', note: 'Auto mode will spend points, equip gear, shop, and pick the destination when the run starts.' },
    auto_best: { icon: Bot, tone: 'text-arcane-400', note: 'Auto mode will spend points, equip gear, and shop, then take the hardest place your current stats can clear. A trip that looks like a defeat waits in town.' },
};

export default function Dashboard(props: Props) {
    const { hero, run } = usePage<SharedProps>().props;
    const tz = useTimezone();
    const next = NEXT_META[props.next.kind];
    const beltCount = props.belt.reduce((n, c) => n + c.belt_qty, 0);
    const winRate = props.counters.trips ? Math.round((props.counters.cleared / props.counters.trips) * 100) : 0;

    return (
        <>
            <Head title="Camp" />
            <div className="relative mb-6 overflow-hidden rounded-2xl border border-ink-700/70 bg-gradient-to-br from-ink-800 via-ink-850 to-ink-900 p-6 sm:p-8">
                <div className="pointer-events-none absolute -top-24 -right-16 size-72 animate-flicker rounded-full bg-lantern-500/15 blur-3xl" />
                <div className="relative flex flex-wrap items-center justify-between gap-6">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.25em] text-ink-400">Lanternford camp</p>
                        <h1 className="mt-1 text-3xl font-extrabold text-lantern-200 text-shadow-glow sm:text-4xl">
                            {hero?.name ?? 'Adventurer'}
                        </h1>
                        <p className="mt-1 text-sm text-ink-300">
                            Level {hero?.level} · {props.counters.trips} expeditions · {winRate}% cleared
                        </p>
                        {hero && hero.unspent > 0 && (
                            <Link href="/character" className="mt-4 inline-flex items-center gap-2 rounded-lg border border-lantern-400/40 bg-lantern-400/10 px-3 py-1.5 text-sm font-semibold text-lantern-200 hover:bg-lantern-400/20">
                                <Sparkles className="size-4" /> {hero.unspent} stat point{hero.unspent === 1 ? '' : 's'} to spend
                            </Link>
                        )}
                    </div>
                    <div className="w-full max-w-sm rounded-xl border border-ink-600/70 bg-ink-950/50 p-4 backdrop-blur">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.2em] text-ink-400">Next expedition</div>
                        <div className={clsx('mt-2 flex items-center gap-3 text-xl font-bold', next.tone)}>
                            <next.icon className="size-6" />
                            <span className="font-display">{props.next.label}</span>
                        </div>
                        <p className="mt-1 text-xs text-ink-400">{next.note}</p>
                        <div className="mt-3 flex items-center gap-2">
                            <Link href="/map" className="btn-primary flex-1">
                                <Compass className="size-4" /> {props.order ? 'Change destination' : 'Choose destination'}
                            </Link>
                        </div>
                        <p className="mt-3 text-[11px] text-ink-500">
                            {run.last?.finished_at
                                ? `Last run ${timeAgo(run.last.finished_at)} (#${run.last.id}).`
                                : 'No runs yet.'}{' '}
                            Runs depart when the bell rings in town.
                        </p>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Panel
                        title="Recent expeditions"
                        action={<Link href="/mail" className="link text-xs">All reports <ArrowRight className="inline size-3" /></Link>}
                        bodyClass="p-0"
                    >
                        {props.recentTrips.length === 0 ? (
                            <Empty icon="🧭" title="No expeditions yet">Pick a destination on the map. You will set off with the next run.</Empty>
                        ) : (
                            <ul className="divide-y divide-ink-700/60">
                                {props.recentTrips.map((t) => (
                                    <li key={t.id}>
                                        <Link href={`/trips/${t.id}`} className="flex items-center gap-3 px-4 py-3 transition hover:bg-ink-800/60 sm:gap-4">
                                            <span className="hidden size-10 shrink-0 place-items-center rounded-lg bg-ink-900 text-xl sm:grid">
                                                {PLACE_ICONS[t.place_type] ?? PLACE_ICONS[placeTypeGuess(t.place_code)] ?? '🗺️'}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-semibold text-ink-100">{t.place}</span>
                                                    <OutcomeChip outcome={t.outcome} />
                                                    {t.level_after > t.level_before && (
                                                        <span className="chip border-arcane-400/50 bg-arcane-500/10 text-arcane-400">Level up!</span>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-400">
                                                    <span>{formatDateTime(t.created_at, tz)}</span>
                                                    <span>{t.encounters_won} fights won</span>
                                                    <Bar value={t.hp_pct} max={100} tone={t.hp_pct > 50 ? 'moss' : t.hp_pct > 20 ? 'lantern' : 'blood'} className="h-1.5 w-16" />
                                                </div>
                                            </div>
                                            <div className="text-right text-sm">
                                                <div className="font-semibold text-arcane-400 tabular-nums">+{t.xp} XP</div>
                                                <Gold amount={t.gold} className="text-xs" />
                                            </div>
                                            <Swords className="hidden size-4 text-ink-500 sm:block" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <StatTile label="Trips" value={props.counters.trips} />
                        <StatTile label="Cleared" value={props.counters.cleared} accent="text-moss-400" />
                        <StatTile label="Retreats" value={props.counters.retreated} accent="text-lantern-300" />
                        <StatTile label="Defeats" value={props.counters.defeats} accent="text-blood-400" />
                        <StatTile label="Kills" value={props.counters.kills} />
                        <StatTile label="Bosses" value={props.counters.boss_kills} accent="text-rarity-legendary" hint={<Skull className="inline size-3" />} />
                    </div>
                </div>

                <div className="space-y-6">
                    <Panel title="Battle readiness" action={<Link href="/character" className="link text-xs">Details</Link>}>
                        <div className="grid grid-cols-2 gap-2">
                            {(['hp', 'attack', 'defense', 'mres', 'crit', 'dodge', 'speed'] as const).map((k) => (
                                <div key={k} className="flex items-center justify-between rounded-md bg-ink-900/60 px-2.5 py-1.5 text-sm">
                                    <span className="text-ink-400">{DERIVED_LABELS[k]}</span>
                                    <span className="font-semibold text-ink-100 tabular-nums">{derivedValue(k, props.derived[k])}</span>
                                </div>
                            ))}
                            <div className="flex items-center justify-between rounded-md bg-ink-900/60 px-2.5 py-1.5 text-sm">
                                <span className="text-ink-400">Damage</span>
                                <span className={clsx('font-semibold capitalize', props.derived.damage_type === 'magic' ? 'text-arcane-400' : 'text-ink-100')}>
                                    {props.derived.damage_type}
                                </span>
                            </div>
                        </div>
                        <div className="mt-4 grid grid-cols-5 gap-1.5">
                            {['weapon', 'offhand', 'head', 'chest', 'hands', 'feet', 'ring1', 'ring2', 'amulet'].map((slot) => {
                                const item = props.equipment[slot];
                                return (
                                    <div
                                        key={slot}
                                        title={item ? `${SLOT_LABELS[slot]}: ${item.name}` : `${SLOT_LABELS[slot]}: empty`}
                                        className={clsx(
                                            'grid aspect-square place-items-center rounded-lg border text-lg',
                                            item ? clsx('bg-ink-800', RARITY_BORDER[item.rarity], RARITY_TEXT[item.rarity]) : 'border-dashed border-ink-700 bg-ink-950/40 text-ink-500 opacity-40',
                                        )}
                                    >
                                        <ItemGlyph icon={item?.icon} slot={item?.template_slot ?? slot} className="size-6" />
                                    </div>
                                );
                            })}
                            <Link href="/character?tab=inventory" className="grid aspect-square place-items-center rounded-lg border border-ink-700 text-xs text-ink-400 hover:border-lantern-400/50 hover:text-lantern-200">
                                Inventory
                            </Link>
                        </div>
                    </Panel>

                    <Panel title="Belt" action={<span className="text-xs text-ink-400 tabular-nums">{beltCount}/{props.beltSlots}</span>}>
                        {props.belt.length === 0 ? (
                            <p className="text-sm text-ink-400">
                                Your belt is empty. <Link href="/character?tab=inventory" className="link">Pack potions</Link> before you set off.
                            </p>
                        ) : (
                            <ul className="space-y-1.5">
                                {props.belt.map((c) => (
                                    <li key={c.id} className="flex items-center justify-between text-sm">
                                        <span className="flex items-center gap-2 text-ink-200">
                                            <ItemGlyph icon={c.icon} className="size-4 text-lantern-300" />
                                            {c.name}
                                        </span>
                                        <span className="font-semibold text-ink-100 tabular-nums">×{c.belt_qty}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title="Latest mail" action={<Link href="/mail" className="link text-xs">Inbox</Link>} bodyClass="p-0">
                        {props.recentMail.length === 0 ? (
                            <Empty icon={<Mail className="mx-auto size-8" />} title="No letters" />
                        ) : (
                            <ul className="divide-y divide-ink-700/60">
                                {props.recentMail.map((m) => (
                                    <li key={m.id}>
                                        <Link href={`/mail/${m.id}`} className="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-ink-800/60">
                                            <span className={clsx('size-2 shrink-0 rounded-full', m.is_read ? 'bg-ink-600' : 'bg-lantern-400 shadow-[0_0_8px_var(--color-lantern-400)]')} />
                                            <span className={clsx('flex-1 truncate', m.is_read ? 'text-ink-300' : 'font-semibold text-ink-100')}>{m.subject}</span>
                                            <span className="text-xs text-ink-500">{timeAgo(m.created_at)}</span>
                                        </Link>
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

function placeTypeGuess(code: string): string {
    const known: Record<string, string> = {
        town: 'rest', brinefen: 'marsh', wreckers_cove: 'coast', tidevault: 'dungeon',
        saltglass_spire: 'ruins', sunken_court: 'dungeon', cinder_wastes: 'cave',
        fallen_citadel: 'ruins', ash_monastery: 'temple', black_stair: 'mountain', crown_of_embers: 'cave',
    };
    if (known[code]) return known[code];
    if (code.includes('mine')) return 'mine';
    if (code.includes('crypt')) return 'dungeon';
    if (code.includes('peak') || code.includes('frost')) return 'mountain';
    if (code.includes('depth') || code.includes('cave')) return 'cave';
    return 'forest';
}
