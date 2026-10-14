import clsx from 'clsx';
import { ArrowDownToLine, Coins, Gem, HeartPulse, Minus, Plus } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import ChestOpen from '@/components/ChestOpen';
import { GearCompare, GearReadout } from '@/components/ItemCard';
import { ItemGlyph } from '@/components/ItemGlyph';
import { Gold, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { confirmDialog } from '@/lib/dialog';
import { INJURY_LABELS, RARITY_BORDER, RARITY_TEXT, SLOT_LABELS, effectLine } from '@/lib/format';
import type { Consumable, Item, PackItem, SealedChest } from '@/types';

interface Props {
    rowVersion: number;
    injury: number;
    equipment: Record<string, Item | undefined>;
    inventory: PackItem[];
    consumables: Consumable[];
    chests: SealedChest[];
    capacity: number;
    beltSlots: number;
    horcruxFull: boolean;
}

const DOLL: (string | null)[][] = [
    [null, 'head', 'amulet'],
    ['weapon', 'chest', 'offhand'],
    ['ring1', 'hands', 'ring2'],
    [null, 'feet', null],
];

export default function InventoryBoard({ rowVersion, injury, equipment, inventory, consumables, chests, capacity, beltSlots, horcruxFull }: Props) {
    const [selected, setSelected] = useState<number | null>(null);
    const [slot, setSlot] = useState<string | null>(null);
    const [belt, setBelt] = useState<Record<number, number>>({});
    const [tip, setTip] = useState<Tip | null>(null);

    useEffect(() => {
        setBelt(Object.fromEntries(consumables.map((c) => [c.id, c.belt_qty])));
    }, [consumables]);

    const worn = slot ? equipment[slot] : undefined;
    const picked = inventory.find((item) => item.id === selected) ?? null;
    const pickedSlot = picked?.target_slot;
    const pickedEquipped = pickedSlot ? equipment[pickedSlot] : undefined;

    useEffect(() => {
        if (!tip) return;
        const hide = () => setTip(null);
        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
        return () => {
            window.removeEventListener('scroll', hide, true);
            window.removeEventListener('resize', hide);
        };
    }, [tip]);
    const cells: (PackItem | null)[] = [...inventory];
    while (cells.length < capacity) cells.push(null);
    const beltTotal = Object.values(belt).reduce((a, b) => a + b, 0);
    const beltDirty = consumables.some((c) => (belt[c.id] ?? 0) !== c.belt_qty);
    const bandage = consumables.find((c) => c.effect_type === 'cure_injury');

    return (
        <div className="space-y-6">
            <ChestOpen />
            {chests.length > 0 && (
                <Panel title="Sealed chests">
                    <ul className="space-y-3">
                        {chests.map((chest) => (
                            <li key={chest.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-lantern-500/40 bg-ink-900/60 px-4 py-3">
                                <div>
                                    <div className="font-display font-bold text-lantern-200">🧰 {chest.place}</div>
                                    <div className="text-xs text-ink-400">{chest.rolls} {chest.rolls === 1 ? 'roll' : 'rolls'} · gear is decided when you open it</div>
                                </div>
                                <button className="btn-primary" onClick={() => act(`/pack/chests/${chest.id}/open`, { row_version: rowVersion })}>Open</button>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

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
                                    onClick={() => { setSlot(slot === name ? null : name); setSelected(null); }}
                                    onHover={(item, rect) => setTip(item ? { rect, item } : null)}
                                />
                            ),
                        )}
                    </div>
                    {worn && slot && (
                        <div className="mt-3">
                            <GearReadout item={worn} />
                            <button className="btn-ghost mt-3 w-full" onClick={() => act('/pack/unequip', { slot, row_version: rowVersion }, { onFinish: () => setSlot(null) })}>
                                <ArrowDownToLine className="size-4" /> Unequip
                            </button>
                        </div>
                    )}
                </Panel>

                <Panel title="Inventory" action={<span className="text-xs tabular-nums text-ink-400">{inventory.length}/{capacity}</span>}>
                    <div className="grid grid-cols-5 gap-1 sm:grid-cols-8">
                        {cells.map((item, i) => (
                            <button
                                key={item?.id ?? `empty-${i}`}
                                type="button"
                                disabled={!item}
                                onClick={() => { if (item) { setSelected(selected === item.id ? null : item.id); setSlot(null); } }}
                                onMouseEnter={(e) => item && setTip({ rect: e.currentTarget.getBoundingClientRect(), item, compare: true })}
                                onMouseLeave={() => setTip(null)}
                                onFocus={(e) => item && setTip({ rect: e.currentTarget.getBoundingClientRect(), item, compare: true })}
                                onBlur={() => setTip(null)}
                                className={clsx(
                                    'relative grid aspect-square place-items-center rounded-md border bg-ink-950/80 transition',
                                    item ? RARITY_BORDER[item.rarity] : 'border-ink-800/80',
                                    item && RARITY_TEXT[item.rarity],
                                    item && selected === item.id && 'ring-2 ring-lantern-400',
                                    item && 'hover:brightness-125',
                                )}
                            >
                                {item && <ItemGlyph icon={item.icon} slot={item.template_slot} className="size-8" />}
                                {item?.two_handed && <span className="absolute right-0.5 bottom-0.5 rounded bg-ink-950/90 px-0.5 text-[8px] font-bold leading-none text-lantern-200">2H</span>}
                            </button>
                        ))}
                    </div>
                    <p className="mt-2 text-[11px] text-ink-500">Hover a piece to compare it with what you have equipped. Click it to equip, sell, or feed the horcrux.</p>
                    {picked && (
                        <div className="mt-3 overflow-x-auto rounded-xl border border-ink-700 bg-ink-950/50 p-3">
                            <GearCompare
                                item={picked}
                                equipped={pickedEquipped}
                                slotLabel={pickedSlot ? (SLOT_LABELS[pickedSlot] ?? pickedSlot) : undefined}
                                delta={picked.delta}
                                note={picked.can_equip ? picked.equip_note : null}
                            />
                            {!picked.can_equip && picked.equip_note && <p className="mt-2 text-[11px] text-blood-400">{picked.equip_note}</p>}
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button className="btn-primary px-3 py-1.5 text-xs" disabled={!picked.can_equip} onClick={() => act('/pack/equip', { item_id: picked.id, slot: picked.target_slot, row_version: rowVersion })}>
                                    Equip
                                </button>
                                <button
                                    className="btn-ghost px-3 py-1.5 text-xs"
                                    onClick={async () => {
                                        const ok = picked.rarity === 'common' || await confirmDialog({
                                            icon: <Coins className="size-8" />,
                                            title: `Sell ${picked.name}?`,
                                            body: <span className="flex items-center justify-center gap-1">The merchant pays <Gold amount={picked.sell_value} /></span>,
                                            confirmLabel: 'Sell',
                                        });
                                        if (ok) act('/market/sell-item', { item_id: picked.id, row_version: rowVersion });
                                    }}
                                >
                                    Sell <Gold amount={picked.sell_value} />
                                </button>
                                <button
                                    className="btn-ghost px-3 py-1.5 text-xs"
                                    disabled={horcruxFull}
                                    title={horcruxFull ? 'The horcrux is full' : `Feeds ${picked.feed} progress`}
                                    onClick={async () => {
                                        const ok = await confirmDialog({
                                            tone: 'danger',
                                            icon: <Gem className="size-8" />,
                                            title: `Feed ${picked.name} to the horcrux?`,
                                            body: `The piece is destroyed and adds ${picked.feed} progress.`,
                                            confirmLabel: 'Feed it',
                                        });
                                        if (ok) act('/character/horcrux', { item_id: picked.id, row_version: rowVersion });
                                    }}
                                >
                                    Feed horcrux (+{picked.feed})
                                </button>
                            </div>
                        </div>
                    )}
                </Panel>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
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
                                                <div className="flex items-center gap-2 truncate text-sm font-semibold text-ink-100">
                                                    <ItemGlyph icon={c.icon} className="size-5 shrink-0 text-lantern-300" />
                                                    <span className="truncate">{c.name}</span>
                                                </div>
                                                <div className="text-[11px] text-ink-400">{effectLine(c.effect_type, c.effect_value)} · own {c.quantity}</div>
                                            </div>
                                            {!manual && (
                                                <div className="flex items-center gap-1">
                                                    <button className="btn-ghost size-7 p-0" disabled={qty <= 0} onClick={() => setBelt((b) => ({ ...b, [c.id]: qty - 1 }))}><Minus className="size-3.5" /></button>
                                                    <span className="w-6 text-center text-sm font-bold tabular-nums">{qty}</span>
                                                    <button className="btn-ghost size-7 p-0" disabled={qty >= c.quantity || beltTotal >= beltSlots} onClick={() => setBelt((b) => ({ ...b, [c.id]: qty + 1 }))}><Plus className="size-3.5" /></button>
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
            {tip && <FloatingTip tip={tip} equipment={equipment} />}
        </div>
    );
}

interface Tip {
    rect: DOMRect;
    item: Item;
    compare?: boolean;
}

function EquipSlot({ name, item, active, onClick, onHover }: {
    name: string;
    item?: Item;
    active: boolean;
    onClick: () => void;
    onHover: (item: Item | null, rect: DOMRect) => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            onMouseEnter={(e) => item && onHover(item, e.currentTarget.getBoundingClientRect())}
            onMouseLeave={() => onHover(null, new DOMRect())}
            onFocus={(e) => item && onHover(item, e.currentTarget.getBoundingClientRect())}
            onBlur={() => onHover(null, new DOMRect())}
            className={clsx(
                'relative grid aspect-square place-items-center rounded-md border bg-ink-950/80',
                item ? RARITY_BORDER[item.rarity] : 'border-dashed border-ink-700',
                item ? RARITY_TEXT[item.rarity] : 'text-ink-500',
                active && 'ring-2 ring-lantern-400',
            )}
        >
            <ItemGlyph icon={item?.icon} slot={item?.template_slot ?? name} className={clsx('size-8', !item && 'opacity-40')} />
            {item?.two_handed && <span className="absolute right-0.5 bottom-0.5 rounded bg-ink-950/90 px-0.5 text-[8px] font-bold leading-none text-lantern-200">2H</span>}
        </button>
    );
}

function FloatingTip({ tip, equipment }: { tip: Tip; equipment: Record<string, Item | undefined> }) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ top: tip.rect.bottom + 8, left: tip.rect.left });
    const bag = tip.compare ? (tip.item as PackItem) : null;
    const slot = bag?.target_slot;
    const equipped = slot ? equipment[slot] : undefined;

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const width = el.offsetWidth;
        const height = el.offsetHeight;
        let left = tip.rect.left + tip.rect.width / 2 - width / 2;
        let top = tip.rect.top - height - 8;
        if (top < 8) top = tip.rect.bottom + 8;
        left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
        if (top + height > window.innerHeight - 8) top = Math.max(8, window.innerHeight - height - 8);
        setPos({ top, left });
    }, [tip]);

    return (
        <div
            ref={ref}
            className="pointer-events-none fixed z-50 rounded-lg border border-ink-600 bg-ink-950 p-2.5 text-left shadow-xl"
            style={{ top: pos.top, left: pos.left }}
        >
            {bag ? (
                <GearCompare
                    item={bag}
                    equipped={equipped}
                    slotLabel={slot ? (SLOT_LABELS[slot] ?? slot) : undefined}
                    delta={bag.delta}
                    note={bag.can_equip ? bag.equip_note : null}
                />
            ) : (
                <div className="w-56">
                    <GearReadout item={tip.item} />
                </div>
            )}
        </div>
    );
}
