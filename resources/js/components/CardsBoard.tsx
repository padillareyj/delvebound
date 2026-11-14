import clsx from 'clsx';
import { Layers, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { ItemGlyph } from '@/components/ItemGlyph';
import { Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { RARITY_BORDER, RARITY_TEXT, SLOT_LABELS, bonusLine } from '@/lib/format';
import type { Item, Rarity } from '@/types';

export interface MonsterCard {
    id: number;
    display_name: string;
    rarity: Rarity;
    item_level: number;
    bonuses: Record<string, number>;
    sell_value: number;
    monster_code: string | null;
    source: string;
    status: string;
}

interface Props {
    rowVersion: number;
    cards: MonsterCard[];
    bagCap: number;
    equipment: Record<string, Item | null> | Item[];
    inventory: Item[];
}

function slottedGear(equipment: Props['equipment'], inventory: Item[]): Item[] {
    const equipped = Array.isArray(equipment) ? equipment : Object.values(equipment);
    const all = [...equipped.filter(Boolean) as Item[], ...inventory];
    return all.filter((item) => (item.card_slots ?? 0) > 0);
}

export default function CardsBoard({ rowVersion, cards, bagCap, equipment, inventory }: Props) {
    const [picked, setPicked] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const gear = slottedGear(equipment, inventory);

    const send = (url: string, data: Record<string, number>) => {
        setBusy(true);
        act(url, { ...data, row_version: rowVersion }, {
            onFinish: () => {
                setBusy(false);
                setPicked(null);
            },
        });
    };

    return (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <Panel
                title={<><Layers className="mr-1 inline size-4" /> Card bag</>}
                action={<span className="text-xs tabular-nums text-ink-400">{cards.length}/{bagCap}</span>}
            >
                <p className="mb-3 text-sm text-ink-400">
                    Monster cards drop on kills. Pick one, then click an empty socket on the right.
                </p>
                {cards.length === 0 ? (
                    <p className="text-sm text-ink-500">No loose cards yet. Slay monsters on expeditions.</p>
                ) : (
                    <ul className="space-y-2">
                        {cards.map((card) => (
                            <li key={card.id}>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => setPicked(picked === card.id ? null : card.id)}
                                    className={clsx(
                                        'w-full rounded-lg border bg-ink-900/50 px-3 py-2 text-left transition',
                                        RARITY_BORDER[card.rarity],
                                        picked === card.id && 'ring-2 ring-lantern-400/70',
                                    )}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <div className={clsx('font-semibold', RARITY_TEXT[card.rarity])}>{card.display_name}</div>
                                            <div className="text-[11px] uppercase tracking-wider text-ink-400">
                                                {card.rarity} · iLvl {card.item_level}
                                            </div>
                                            <ul className="mt-1 space-y-0.5 text-xs text-moss-400">
                                                {Object.entries(card.bonuses).map(([key, value]) => (
                                                    <li key={key}>{bonusLine(key, value)}</li>
                                                ))}
                                            </ul>
                                        </div>
                                        <button
                                            type="button"
                                            className="btn-ghost px-2 py-1 text-xs"
                                            disabled={busy}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                send('/character/cards/sell', { card_id: card.id });
                                            }}
                                        >
                                            Sell {card.sell_value}g
                                        </button>
                                    </div>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title={<><Sparkles className="mr-1 inline size-4" /> Socketed gear</>}>
                {picked !== null && (
                    <p className="mb-3 rounded-lg border border-lantern-400/40 bg-lantern-400/10 px-3 py-2 text-sm text-lantern-200">
                        Card selected — click an empty socket below.
                    </p>
                )}
                {gear.length === 0 ? (
                    <p className="text-sm text-ink-500">
                        No slotted gear yet. Higher rarity drops are more likely to roll card sockets.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {gear.map((item) => (
                            <li key={item.id} className={clsx('rounded-lg border bg-ink-900/40 p-3', RARITY_BORDER[item.rarity])}>
                                <div className="flex items-center gap-2">
                                    <span className={clsx('grid size-9 place-items-center rounded-md border bg-ink-950/80', RARITY_BORDER[item.rarity], RARITY_TEXT[item.rarity])}>
                                        <ItemGlyph icon={item.icon} slot={item.template_slot} className="size-6" />
                                    </span>
                                    <div className="min-w-0">
                                        <div className={clsx('truncate font-semibold', RARITY_TEXT[item.rarity])}>{item.name}</div>
                                        <div className="text-[11px] text-ink-400">
                                            {SLOT_LABELS[item.equip_slot ?? item.template_slot] ?? item.template_slot}
                                            {item.equip_slot ? ' · equipped' : ' · pack'}
                                            {' · '}{item.card_slots} slot{item.card_slots === 1 ? '' : 's'}
                                        </div>
                                    </div>
                                </div>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {Array.from({ length: item.card_slots }, (_, index) => {
                                        const card = item.cards?.[index] ?? null;
                                        if (card) {
                                            return (
                                                <button
                                                    key={index}
                                                    type="button"
                                                    disabled={busy}
                                                    className={clsx('min-w-[7.5rem] flex-1 rounded-lg border px-2 py-2 text-left', RARITY_BORDER[card.rarity], 'bg-ink-950/70')}
                                                    onClick={() => send('/character/cards/remove', { item_id: item.id, slot_index: index })}
                                                    title="Remove card"
                                                >
                                                    <div className={clsx('text-xs font-semibold', RARITY_TEXT[card.rarity])}>{card.display_name}</div>
                                                    <div className="text-[10px] text-ink-400">Socket {index + 1} · click to remove</div>
                                                </button>
                                            );
                                        }
                                        return (
                                            <button
                                                key={index}
                                                type="button"
                                                disabled={busy || picked === null}
                                                className={clsx(
                                                    'min-w-[7.5rem] flex-1 rounded-lg border border-dashed border-ink-600 px-2 py-2 text-left text-ink-400',
                                                    picked !== null && 'border-lantern-400/60 text-lantern-200 hover:bg-lantern-400/10',
                                                )}
                                                onClick={() => picked !== null && send('/character/cards/insert', {
                                                    card_id: picked,
                                                    item_id: item.id,
                                                    slot_index: index,
                                                })}
                                            >
                                                <div className="text-xs font-semibold">Empty socket</div>
                                                <div className="text-[10px]">#{index + 1}</div>
                                            </button>
                                        );
                                    })}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </div>
    );
}
