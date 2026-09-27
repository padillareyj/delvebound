import clsx from 'clsx';
import type { ReactNode } from 'react';
import { RARITY_BORDER, RARITY_GLOW, RARITY_TEXT, SLOT_LABELS, STAT_NAMES, modLine } from '@/lib/format';
import type { Item, Rarity } from '@/types';

const SLOT_GLYPH: Record<string, string> = {
    weapon: '⚔️',
    offhand: '🛡️',
    head: '⛑️',
    chest: '🥋',
    hands: '🧤',
    feet: '🥾',
    ring: '💍',
    ring1: '💍',
    ring2: '💍',
    amulet: '📿',
};

export function slotGlyph(slot: string): string {
    return SLOT_GLYPH[slot] ?? '✦';
}

export function RarityName({ name, rarity, className }: { name: string; rarity: Rarity; className?: string }) {
    return <span className={clsx('font-semibold', RARITY_TEXT[rarity], className)}>{name}</span>;
}

export function ItemLines({ item }: { item: Item }) {
    const base: string[] = [];
    if (item.power) base.push(`${item.power} Power`);
    if (item.armor) base.push(`${item.armor} Armor`);
    if (item.mres) base.push(`${item.mres} Magic Res`);
    return (
        <div className="space-y-1 text-xs">
            {base.length > 0 && <div className="font-semibold text-ink-100">{base.join(' · ')}</div>}
            {item.scaling_stat && (
                <div className="text-ink-300">
                    Scales with {STAT_NAMES[item.scaling_stat]?.name ?? item.scaling_stat}
                    {item.two_handed && ' · Two-handed'}
                </div>
            )}
            {Object.entries(item.implicit ?? {}).map(([k, v]) => (
                <div key={k} className="text-ink-200">{modLine(k, v)}</div>
            ))}
            {item.affixes.map((a, i) => (
                <div key={i} className="text-arcane-400">{modLine(a.stat_key, a.value)}</div>
            ))}
        </div>
    );
}

export function ItemCard({ item, children, footer, compact, highlight }: {
    item: Item;
    children?: ReactNode;
    footer?: ReactNode;
    compact?: boolean;
    highlight?: boolean;
}) {
    return (
        <article
            className={clsx(
                'group relative flex flex-col rounded-xl border bg-gradient-to-b from-ink-800/80 to-ink-900/90 p-3 transition',
                RARITY_BORDER[item.rarity],
                RARITY_GLOW[item.rarity],
                highlight && 'ring-2 ring-lantern-400/60',
                'hover:-translate-y-0.5 hover:border-lantern-400/40',
            )}
        >
            <div className="flex items-start gap-3">
                <div className="grid size-10 shrink-0 place-items-center rounded-lg border border-ink-700 bg-ink-950/70 text-xl">
                    {slotGlyph(item.template_slot)}
                </div>
                <div className="min-w-0 flex-1">
                    <RarityName name={item.name} rarity={item.rarity} className="block truncate text-sm leading-tight" />
                    <div className="mt-0.5 text-[11px] uppercase tracking-wider text-ink-400">
                        {item.rarity} {SLOT_LABELS[item.template_slot] ?? item.template_slot} · iLvl {item.item_level}
                        {item.req_level > 1 && ` · Req ${item.req_level}`}
                    </div>
                </div>
            </div>
            {!compact && (
                <div className="mt-2.5">
                    <ItemLines item={item} />
                </div>
            )}
            {children}
            {footer && <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-ink-700/60 pt-2.5">{footer}</div>}
        </article>
    );
}
