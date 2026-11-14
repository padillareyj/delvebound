import { Head } from '@inertiajs/react';
import clsx from 'clsx';
import { Lock, ShoppingBag, Tag } from 'lucide-react';
import { useState } from 'react';
import { ItemCard } from '@/components/ItemCard';
import { ItemGlyph } from '@/components/ItemGlyph';
import SectionNav from '@/components/SectionNav';
import { Empty, Gold, PageHeader, Panel } from '@/components/ui';
import { MARKET_NAV } from '@/lib/nav';
import { act } from '@/lib/act';
import { confirmDialog } from '@/lib/dialog';
import { SLOT_LABELS, STAT_NAMES, effectLine, formatDateTime, modLine, useTimezone } from '@/lib/format';
import type { Consumable, Item, StatKey } from '@/types';

interface Listing {
    id: number;
    kind: 'gear' | 'consumable';
    price: number;
    min_level: number;
    name: string;
    description: string | null;
    slot: string | null;
    item_type: string | null;
    scaling_stat: StatKey | null;
    two_handed: boolean;
    power: number;
    armor: number;
    mres: number;
    implicit: Record<string, number>;
    code: string | null;
    icon: string;
    effect_type: string | null;
    effect_value: number | null;
    use_trigger: string | null;
    owned: number | null;
    locked: boolean;
}

interface LedgerRow {
    id: number;
    actor: string;
    action: string;
    quantity: number;
    gold_delta: number;
    gold_after: number;
    what: string;
    created_at: string;
}

interface Props {
    rowVersion: number;
    gold: number;
    level: number;
    listings: Listing[];
    inventory: Item[];
    consumables: Consumable[];
    packCount: number;
    capacity: number;
    maxStack: number;
    ledger: LedgerRow[];
}

export default function Market(props: Props) {
    const tz = useTimezone();
    const [tab, setTab] = useState<'buy' | 'sell'>('buy');
    const [qty, setQty] = useState<Record<number, number>>({});
    const packFull = props.packCount >= props.capacity;

    const consumables = props.listings.filter((l) => l.kind === 'consumable');
    const gear = props.listings.filter((l) => l.kind === 'gear');

    return (
        <>
            <Head title="Market" />
            <SectionNav entry={MARKET_NAV} />
            <PageHeader title="Lanternford Market" subtitle="Old Marrow's stall: potions, tonics, and honest starter steel.">
                <div className="rounded-lg border border-ink-700 bg-ink-900/60 px-4 py-2">
                    <span className="mr-2 text-xs text-ink-400">Purse</span>
                    <Gold amount={props.gold} className="text-lg" />
                </div>
                <div className="flex rounded-lg border border-ink-700 bg-ink-900/60 p-1">
                    {(['buy', 'sell'] as const).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={clsx('rounded-md px-4 py-1.5 text-sm font-semibold capitalize', tab === t ? 'bg-lantern-400 text-on-accent' : 'text-ink-300 hover:text-ink-100')}
                        >
                            {t}
                        </button>
                    ))}
                </div>
            </PageHeader>

            {tab === 'buy' ? (
                <div className="space-y-6">
                    <Panel title="Potions & tonics">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {consumables.map((l) => {
                                const q = qty[l.id] ?? 1;
                                const room = props.maxStack - (l.owned ?? 0);
                                const total = l.price * q;
                                return (
                                    <div key={l.id} className={clsx('flex flex-col rounded-xl border border-ink-700 bg-gradient-to-b from-ink-800/70 to-ink-900 p-4', l.locked && 'opacity-50')}>
                                        <div className="flex items-start justify-between">
                                            <span className="grid size-12 place-items-center rounded-lg border border-ink-700 bg-ink-950 text-lantern-300">
                                                <ItemGlyph icon={l.icon} className="size-8" />
                                            </span>
                                            <Gold amount={l.price} />
                                        </div>
                                        <h3 className="mt-2 font-sans text-sm font-bold tracking-normal text-ink-100">{l.name}</h3>
                                        <p className="mt-0.5 text-xs text-lantern-300">{effectLine(l.effect_type, l.effect_value)}</p>
                                        {l.description && <p className="mt-1 flex-1 text-xs text-ink-400">{l.description}</p>}
                                        <div className="mt-3 text-[11px] text-ink-500">
                                            Own {l.owned ?? 0} / {props.maxStack}
                                            {l.locked && <> · <Lock className="inline size-3" /> Level {l.min_level}</>}
                                        </div>
                                        <div className="mt-2 flex items-center gap-2">
                                            <select
                                                className="input w-16 px-2 py-1.5"
                                                value={q}
                                                disabled={l.locked || room <= 0}
                                                onChange={(e) => setQty((s) => ({ ...s, [l.id]: Number(e.target.value) }))}
                                            >
                                                {[1, 2, 3, 5, 10].filter((n) => n <= Math.max(1, room)).map((n) => (
                                                    <option key={n} value={n}>×{n}</option>
                                                ))}
                                            </select>
                                            <button
                                                className="btn-primary flex-1 py-1.5 text-xs"
                                                disabled={l.locked || room < q || props.gold < total}
                                                onClick={() => act('/market/buy', { listing_id: l.id, quantity: q, row_version: props.rowVersion })}
                                            >
                                                <ShoppingBag className="size-3.5" /> Buy {q > 1 && <Gold amount={total} className="text-on-accent!" />}
                                            </button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </Panel>

                    <Panel title="Armory" action={packFull && <span className="text-xs text-blood-400">Pack full ({props.packCount}/{props.capacity})</span>}>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {gear.map((l) => (
                                <div key={l.id} className={clsx('flex gap-3 rounded-xl border border-ink-700 bg-ink-900/60 p-3', l.locked && 'opacity-50')}>
                                    <div className="grid size-12 shrink-0 place-items-center rounded-lg border border-ink-600 bg-ink-950 text-rarity-common">
                                        <ItemGlyph icon={l.icon} slot={l.slot} className="size-8" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <h3 className="truncate font-sans text-sm font-bold tracking-normal text-rarity-common">{l.name}</h3>
                                            <Gold amount={l.price} />
                                        </div>
                                        <div className="text-[11px] uppercase tracking-wider text-ink-400">
                                            {SLOT_LABELS[l.slot ?? ''] ?? l.slot} · Level {l.min_level}
                                        </div>
                                        <div className="mt-1 space-y-0.5 text-xs text-ink-300">
                                            {[l.power && `${l.power} Power`, l.armor && `${l.armor} Armor`, l.mres && `${l.mres} Magic Res`].filter(Boolean).join(' · ')}
                                            {l.scaling_stat && <div className="text-ink-400">Scales with {STAT_NAMES[l.scaling_stat].name}{l.two_handed && ' · Two-handed'}</div>}
                                            {Object.entries(l.implicit).map(([k, v]) => <div key={k}>{modLine(k, v)}</div>)}
                                        </div>
                                        <button
                                            className="btn-ghost mt-2 w-full py-1.5 text-xs"
                                            disabled={l.locked || packFull || props.gold < l.price}
                                            onClick={() => act('/market/buy', { listing_id: l.id, row_version: props.rowVersion })}
                                        >
                                            {l.locked ? <><Lock className="size-3.5" /> Requires level {l.min_level}</> : 'Buy'}
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Panel title="Sell gear" className="lg:col-span-2">
                        {props.inventory.length === 0 ? (
                            <Empty icon="🎒" title="Nothing to sell">Equipped items must be unequipped before you can sell them.</Empty>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {props.inventory.map((item) => (
                                    <ItemCard
                                        key={item.id}
                                        item={item}
                                        compact
                                        footer={
                                            <button
                                                className="btn-ghost w-full py-1.5 text-xs"
                                                onClick={async () => {
                                                    const ok = item.rarity === 'common' || await confirmDialog({
                                                        icon: <Tag className="size-8" />,
                                                        title: `Sell ${item.name}?`,
                                                        body: <span className="flex items-center justify-center gap-1">The merchant pays <Gold amount={item.sell_value} /></span>,
                                                        confirmLabel: 'Sell',
                                                    });
                                                    if (ok) act('/market/sell-item', { item_id: item.id, row_version: props.rowVersion });
                                                }}
                                            >
                                                <Tag className="size-3.5" /> Sell for <Gold amount={item.sell_value} />
                                            </button>
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </Panel>
                    <Panel title="Sell supplies">
                        {props.consumables.length === 0 ? (
                            <p className="text-sm text-ink-400">No supplies to sell.</p>
                        ) : (
                            <ul className="space-y-2">
                                {props.consumables.map((c) => (
                                    <li key={c.id} className="flex items-center justify-between gap-2 rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2 text-sm">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2 truncate font-semibold">
                                                <ItemGlyph icon={c.icon} className="size-5 shrink-0 text-lantern-300" />
                                                <span className="truncate">{c.name}</span>
                                            </div>
                                            <div className="text-[11px] text-ink-400">
                                                ×{c.quantity}{c.belt_qty > 0 && ` (${c.belt_qty} on belt)`} · <Gold amount={c.sell_price} className="text-[11px]" /> each
                                            </div>
                                        </div>
                                        <button
                                            className="btn-ghost px-2.5 py-1 text-xs"
                                            onClick={() => act('/market/sell-consumable', { consumable_id: c.id, quantity: 1, row_version: props.rowVersion })}
                                        >
                                            Sell 1
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            )}

            <Panel title="Ledger" className="mt-6" bodyClass="p-0">
                {props.ledger.length === 0 ? (
                    <Empty title="No transactions yet" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-[11px] uppercase tracking-wider text-ink-400">
                                <tr className="border-b border-ink-700/60">
                                    <th className="px-4 py-2">When</th>
                                    <th className="px-4 py-2">Action</th>
                                    <th className="px-4 py-2">Item</th>
                                    <th className="px-4 py-2 text-right">Gold</th>
                                    <th className="px-4 py-2 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink-800">
                                {props.ledger.map((t) => (
                                    <tr key={t.id}>
                                        <td className="px-4 py-2 whitespace-nowrap text-ink-400">{formatDateTime(t.created_at, tz)}</td>
                                        <td className="px-4 py-2 capitalize">
                                            {t.action}
                                            {t.actor !== 'player' && <span className="ml-1 text-[10px] text-ink-500">({t.actor})</span>}
                                        </td>
                                        <td className="px-4 py-2">{t.quantity > 1 && `${t.quantity} × `}{t.what}</td>
                                        <td className={clsx('px-4 py-2 text-right font-semibold tabular-nums', t.gold_delta > 0 ? 'text-moss-400' : t.gold_delta < 0 ? 'text-blood-400' : 'text-ink-400')}>
                                            {t.gold_delta > 0 ? '+' : ''}{t.gold_delta}
                                        </td>
                                        <td className="px-4 py-2 text-right text-ink-300 tabular-nums">{t.gold_after}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </>
    );
}
