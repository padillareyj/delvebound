import { Head } from '@inertiajs/react';
import clsx from 'clsx';
import { ArrowDownToLine, HeartPulse, Minus, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import ChestOpen from '@/components/ChestOpen';
import { ItemCard, ItemLines, RarityName, slotGlyph } from '@/components/ItemCard';
import { Empty, Gold, PageHeader, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { DERIVED_LABELS, INJURY_LABELS, RARITY_BORDER, SLOT_LABELS, derivedDelta, derivedValue, effectLine } from '@/lib/format';
import type { Consumable, Derived, Item, PackItem, SealedChest } from '@/types';

interface Props {
    rowVersion: number;
    level: number;
    gold: number;
    injury: number;
    equipment: Record<string, Item>;
    inventory: PackItem[];
    consumables: Consumable[];
    chests: SealedChest[];
    capacity: number;
    beltSlots: number;
    slots: string[];
    derived: Derived;
}

const DOLL: (string | null)[][] = [
    [null, 'head', 'amulet'],
    ['weapon', 'chest', 'offhand'],
    ['ring1', 'hands', 'ring2'],
    [null, 'feet', null],
];

const FILTERS = ['all', 'weapon', 'offhand', 'head', 'chest', 'hands', 'feet', 'ring', 'amulet'];

export default function Pack({ rowVersion, injury, equipment, inventory, consumables, chests, capacity, beltSlots, derived }: Props) {
    const [filter, setFilter] = useState('all');
    const [selectedSlot, setSelectedSlot] = useState<string | null>(null);
    const [belt, setBelt] = useState<Record<number, number>>({});

    useEffect(() => {
        setBelt(Object.fromEntries(consumables.map((c) => [c.id, c.belt_qty])));
    }, [consumables]);

    const shown = useMemo(
        () => inventory.filter((i) => filter === 'all' || i.template_slot === filter),
        [inventory, filter],
    );
    const beltTotal = Object.values(belt).reduce((a, b) => a + b, 0);
    const beltDirty = consumables.some((c) => (belt[c.id] ?? 0) !== c.belt_qty);
    const bandage = consumables.find((c) => c.effect_type === 'cure_injury');
    const selected = selectedSlot ? equipment[selectedSlot] : null;

    return (
        <>
            <Head title="Pack" />
            <ChestOpen />
            <PageHeader title="Pack" subtitle={`${inventory.length} of ${capacity} pack slots used. A full pack sells the cheapest common to make room.`} />
            {chests.length > 0 && (
                <Panel title="Sealed chests" className="mb-6">
                    <ul className="space-y-3">
                        {chests.map((chest) => (
                            <li key={chest.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-lantern-500/40 bg-ink-900/60 px-4 py-3">
                                <div>
                                    <div className="font-display font-bold text-lantern-200">🧰 {chest.place}</div>
                                    <div className="text-xs text-ink-400">
                                        {chest.rolls} {chest.rolls === 1 ? 'roll' : 'rolls'} · gear is decided when you open it
                                    </div>
                                </div>
                                <button className="btn-primary" onClick={() => act(`/pack/chests/${chest.id}/open`, { row_version: rowVersion })}>
                                    Open
                                </button>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[22rem_1fr]">
                <div className="space-y-6">
                    <Panel title="Equipped">
                        <div className="grid grid-cols-3 gap-2">
                            {DOLL.flat().map((slot, i) =>
                                slot === null ? (
                                    <div key={i} />
                                ) : (
                                    <button
                                        key={slot}
                                        onClick={() => setSelectedSlot(selectedSlot === slot ? null : slot)}
                                        className={clsx(
                                            'relative flex aspect-square flex-col items-center justify-center gap-1 rounded-xl border bg-ink-900/70 p-1 text-center transition hover:border-lantern-400/50',
                                            equipment[slot] ? RARITY_BORDER[equipment[slot].rarity] : 'border-dashed border-ink-700',
                                            selectedSlot === slot && 'ring-2 ring-lantern-400',
                                        )}
                                    >
                                        <span className={clsx('text-2xl', !equipment[slot] && 'opacity-25')}>{slotGlyph(slot)}</span>
                                        <span className="line-clamp-2 text-[10px] leading-tight">
                                            {equipment[slot] ? (
                                                <RarityName name={equipment[slot].name} rarity={equipment[slot].rarity} />
                                            ) : (
                                                <span className="text-ink-500">{SLOT_LABELS[slot]}</span>
                                            )}
                                        </span>
                                    </button>
                                ),
                            )}
                        </div>
                        {selected && selectedSlot && (
                            <div className="mt-4 animate-rise rounded-xl border border-ink-700 bg-ink-950/50 p-3">
                                <RarityName name={selected.name} rarity={selected.rarity} className="text-sm" />
                                <div className="mb-2 text-[11px] uppercase tracking-wider text-ink-400">
                                    {selected.rarity} · iLvl {selected.item_level}
                                </div>
                                <ItemLines item={selected} />
                                <button className="btn-ghost mt-3 w-full" onClick={() => act('/pack/unequip', { slot: selectedSlot, row_version: rowVersion }, { onFinish: () => setSelectedSlot(null) })}>
                                    <ArrowDownToLine className="size-4" /> Unequip to pack
                                </button>
                            </div>
                        )}
                        <div className="mt-4 grid grid-cols-2 gap-1.5 text-xs">
                            {(['hp', 'attack', 'defense', 'mres', 'crit', 'dodge', 'speed', 'luck'] as const).map((k) => (
                                <div key={k} className="flex justify-between rounded bg-ink-900/60 px-2 py-1">
                                    <span className="text-ink-400">{DERIVED_LABELS[k]}</span>
                                    <span className="font-semibold tabular-nums">{derivedValue(k, derived[k] ?? 0)}</span>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <Panel title="Belt" action={<span className={clsx('text-xs tabular-nums', beltTotal > beltSlots ? 'text-blood-400' : 'text-ink-400')}>{beltTotal}/{beltSlots}</span>}>
                        {consumables.length === 0 ? (
                            <p className="text-sm text-ink-400">No consumables. The Market sells potions and tonics.</p>
                        ) : (
                            <ul className="space-y-2">
                                {consumables.map((c) => {
                                    const manual = c.use_trigger === 'manual';
                                    const qty = belt[c.id] ?? 0;
                                    return (
                                        <li key={c.id} className="rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2">
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-semibold text-ink-100">{c.name}</div>
                                                    <div className="text-[11px] text-ink-400">{effectLine(c.effect_type, c.effect_value)} · own {c.quantity}</div>
                                                </div>
                                                {!manual && (
                                                    <div className="flex items-center gap-1">
                                                        <button className="btn-ghost size-7 p-0" disabled={qty <= 0} onClick={() => setBelt((b) => ({ ...b, [c.id]: qty - 1 }))}>
                                                            <Minus className="size-3.5" />
                                                        </button>
                                                        <span className="w-6 text-center text-sm font-bold tabular-nums">{qty}</span>
                                                        <button
                                                            className="btn-ghost size-7 p-0"
                                                            disabled={qty >= c.quantity || beltTotal >= beltSlots}
                                                            onClick={() => setBelt((b) => ({ ...b, [c.id]: qty + 1 }))}
                                                        >
                                                            <Plus className="size-3.5" />
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        {consumables.length > 0 && (
                            <button className="btn-primary mt-3 w-full" disabled={!beltDirty || beltTotal > beltSlots} onClick={() => act('/pack/belt', { belt, row_version: rowVersion })}>
                                Pack the belt
                            </button>
                        )}
                    </Panel>

                    {injury > 0 && (
                        <Panel title="Injury">
                            <p className="text-sm text-blood-400">🩸 {INJURY_LABELS[injury]}</p>
                            <p className="mt-1 text-xs text-ink-400">
                                {injury === 1 ? 'Your next trip starts with −20% max HP and −10% attack.' : 'You must rest in town for the next run.'}
                            </p>
                            {injury === 1 && (
                                <button className="btn-primary mt-3 w-full" disabled={!bandage} onClick={() => act('/pack/bandage', { row_version: rowVersion })}>
                                    <HeartPulse className="size-4" /> {bandage ? `Use Bandage Kit (${bandage.quantity})` : 'No Bandage Kit (buy one at the Market)'}
                                </button>
                            )}
                        </Panel>
                    )}
                </div>

                <Panel
                    title="Inventory"
                    action={
                        <div className="flex flex-wrap gap-1">
                            {FILTERS.map((f) => (
                                <button
                                    key={f}
                                    onClick={() => setFilter(f)}
                                    className={clsx(
                                        'rounded-md px-2 py-1 text-xs font-medium capitalize',
                                        filter === f ? 'bg-lantern-400/15 text-lantern-200' : 'text-ink-400 hover:text-ink-100',
                                    )}
                                >
                                    {f === 'all' ? 'All' : SLOT_LABELS[f] ?? f}
                                </button>
                            ))}
                        </div>
                    }
                >
                    {shown.length === 0 ? (
                        <Empty icon="🎒" title={inventory.length ? 'Nothing in this slot' : 'Your pack is empty'}>
                            Loot from expeditions lands here.
                        </Empty>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                            {shown.map((item) => (
                                <ItemCard
                                    key={item.id}
                                    item={item}
                                    footer={
                                        <>
                                            <button
                                                className="btn-primary px-3 py-1.5 text-xs"
                                                disabled={!item.can_equip}
                                                title={item.equip_note ?? undefined}
                                                onClick={() => act('/pack/equip', { item_id: item.id, slot: item.target_slot, row_version: rowVersion })}
                                            >
                                                Equip{item.target_slot.startsWith('ring') ? ` (${SLOT_LABELS[item.target_slot]})` : ''}
                                            </button>
                                            <button
                                                className="btn-ghost px-3 py-1.5 text-xs"
                                                onClick={() => {
                                                    if (item.rarity === 'common' || window.confirm(`Sell ${item.name} for ${item.sell_value} gold?`)) {
                                                        act('/market/sell-item', { item_id: item.id, row_version: rowVersion });
                                                    }
                                                }}
                                            >
                                                Sell <Gold amount={item.sell_value} />
                                            </button>
                                        </>
                                    }
                                >
                                    <DeltaList delta={item.delta} />
                                    {!item.can_equip && item.equip_note && <p className="mt-2 text-[11px] text-blood-400">{item.equip_note}</p>}
                                    {item.can_equip && item.equip_note && <p className="mt-2 text-[11px] text-lantern-300">{item.equip_note}</p>}
                                </ItemCard>
                            ))}
                        </div>
                    )}
                </Panel>
            </div>
        </>
    );
}

function DeltaList({ delta }: { delta: Record<string, number> }) {
    const entries = Object.entries(delta);
    if (!entries.length) return <p className="mt-2 text-[11px] text-ink-500">No change to your stats.</p>;
    return (
        <div className="mt-2 flex flex-wrap gap-1">
            {entries.map(([k, v]) => (
                <span
                    key={k}
                    className={clsx(
                        'rounded px-1.5 py-0.5 text-[11px] font-semibold tabular-nums',
                        v > 0 ? 'bg-moss-500/10 text-moss-400' : 'bg-blood-500/10 text-blood-400',
                    )}
                >
                    {derivedDelta(k, v)} {DERIVED_LABELS[k] ?? k}
                </span>
            ))}
        </div>
    );
}
