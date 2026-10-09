import clsx from 'clsx';
import type { ReactNode } from 'react';
import { ItemGlyph } from '@/components/ItemGlyph';
import { DERIVED_LABELS, RARITY_BORDER, RARITY_GLOW, RARITY_TEXT, SLOT_LABELS, STAT_NAMES, derivedDelta, modLine } from '@/lib/format';
import type { Item, Rarity } from '@/types';

export function slotGlyph(slot: string, className = 'size-6'): ReactNode {
    return <ItemGlyph slot={slot} className={className} />;
}

const STAT_ORDER = [
    'power', 'armor', 'mres', 'attack', 'hp', 'str', 'dex', 'int', 'vit', 'luk',
    'crit_pct', 'crit_dmg_pct', 'dodge_pct', 'speed', 'gold_find_pct', 'magic_find_pct',
];

const BASE_LABELS: Record<string, string> = { power: 'Power', armor: 'Armor', mres: 'Magic Res' };

function statTotals(item: Item): Record<string, number> {
    const totals: Record<string, number> = {};
    const add = (key: string, value: number) => {
        if (!value) return;
        totals[key] = (totals[key] ?? 0) + value;
    };
    add('power', item.power);
    add('armor', item.armor);
    add('mres', item.mres);
    for (const [key, value] of Object.entries(item.implicit ?? {})) add(key, value);
    for (const affix of item.affixes) add(affix.stat_key, affix.value);
    return totals;
}

function statText(key: string, value: number): string {
    if (key in BASE_LABELS) return `${value} ${BASE_LABELS[key]}`;
    return modLine(key, value);
}

interface StatRow {
    key: string;
    text: string;
    tone: 'up' | 'down' | 'flat';
}

function compareRows(item: Item, against: Item | null): StatRow[] {
    const mine = statTotals(item);
    const theirs = against ? statTotals(against) : null;
    const keys = new Set([...Object.keys(mine), ...(theirs ? Object.keys(theirs) : [])]);
    const ordered = [
        ...STAT_ORDER.filter((key) => keys.has(key)),
        ...[...keys].filter((key) => !STAT_ORDER.includes(key)).sort(),
    ];

    return ordered.flatMap((key) => {
        const value = mine[key] ?? 0;
        const delta = theirs ? value - (theirs[key] ?? 0) : 0;
        if (value === 0 && delta === 0) return [];
        const tone: StatRow['tone'] = !theirs || delta === 0 ? 'flat' : delta > 0 ? 'up' : 'down';
        const text = value === 0 ? statText(key, delta) : statText(key, value);
        const mark = key.endsWith('_pct') ? '%' : '';
        const suffix = theirs && delta !== 0 && value !== 0 ? ` (${delta > 0 ? '+' : ''}${delta}${mark})` : '';
        return [{ key, text: `${text}${suffix}`, tone }];
    });
}

const TONE_CLASS = { up: 'text-moss-400', down: 'text-blood-400', flat: 'text-ink-200' };

export function GearReadout({ item, against }: { item: Item; against?: Item | null }) {
    const rows = against ? compareRows(item, against) : [];
    return (
        <div>
            <RarityName name={item.name} rarity={item.rarity} className="block text-sm leading-tight" />
            <div className={clsx('mt-0.5 text-[10px] uppercase tracking-wider', RARITY_TEXT[item.rarity])}>
                {item.rarity} {typeLabel(item)} · iLvl {item.item_level}
                {item.req_level > 1 && ` · Req ${item.req_level}`}
            </div>
            <div className="mt-1">
                {against ? (
                    <div className="space-y-0.5 text-xs">
                        {rows.map((row) => (
                            <div key={row.key} className={clsx('font-semibold tabular-nums', TONE_CLASS[row.tone])}>{row.text}</div>
                        ))}
                        {item.scaling_stat && (
                            <div className="font-normal text-ink-300">
                                Scales with {STAT_NAMES[item.scaling_stat]?.name ?? item.scaling_stat}
                                {item.two_handed && ' · Two-handed'}
                            </div>
                        )}
                    </div>
                ) : (
                    <ItemLines item={item} />
                )}
            </div>
        </div>
    );
}

function typeLabel(item: Item): string {
    const slot = SLOT_LABELS[item.template_slot] ?? item.template_slot;
    const kind = item.item_type ? item.item_type.charAt(0).toUpperCase() + item.item_type.slice(1) : '';
    if (item.template_slot === 'weapon' && kind) return item.two_handed ? `2H ${kind}` : kind;
    if (item.item_type === 'shield') return 'Shield';
    if (item.item_type === 'cloth' || item.item_type === 'leather' || item.item_type === 'mail') return `${kind} ${slot}`;
    return slot;
}

export function GearCompare({
    item,
    equipped,
    slotLabel,
    delta,
    note,
}: {
    item: Item;
    equipped?: Item | null;
    slotLabel?: string;
    delta?: Record<string, number>;
    note?: string | null;
}) {
    const changes = delta ? Object.entries(delta) : [];
    return (
        <div className={clsx('flex items-start', equipped && 'gap-3')}>
            <div className={equipped ? 'w-52 shrink-0' : 'min-w-52'}>
                <GearReadout item={item} against={equipped ?? null} />
                {delta && (
                    <div className="mt-2 border-t border-ink-700/80 pt-1.5">
                        <div className="text-[10px] font-semibold uppercase tracking-wider text-ink-500">On you</div>
                        {changes.length === 0 ? (
                            <p className="mt-0.5 text-[11px] text-ink-400">No change to your combat stats.</p>
                        ) : (
                            <div className="mt-1 flex flex-wrap gap-1">
                                {changes.map(([key, value]) => (
                                    <span key={key} className={clsx('rounded px-1.5 py-0.5 text-[11px] font-semibold tabular-nums', value > 0 ? 'bg-moss-500/15 text-moss-400' : 'bg-blood-500/15 text-blood-400')}>
                                        {derivedDelta(key, value)} {DERIVED_LABELS[key] ?? key}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                )}
                {note && <p className="mt-1.5 text-[11px] leading-snug text-lantern-300">{note}</p>}
            </div>
            {equipped && (
                <div className="w-52 shrink-0 border-l border-ink-700 pl-3">
                    <div className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-ink-500">
                        Equipped{slotLabel ? ` · ${slotLabel}` : ''}
                    </div>
                    <GearReadout item={equipped} />
                </div>
            )}
        </div>
    );
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
                <div className={clsx('grid size-10 shrink-0 place-items-center rounded-lg border border-ink-700 bg-ink-950/70', RARITY_TEXT[item.rarity])}>
                    <ItemGlyph slot={item.template_slot} itemType={item.item_type} twoHanded={item.two_handed} className="size-6" />
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
