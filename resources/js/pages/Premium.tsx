import { Head } from '@inertiajs/react';
import clsx from 'clsx';
import { Check, Gem, ShoppingCart, Sparkles, Wallet, X } from 'lucide-react';
import { useState } from 'react';
import { CATEGORY_LABELS, Portrait, TIER, TitleBadge, styleOf } from '@/components/Premium';
import SectionNav from '@/components/SectionNav';
import { Empty, Gold, PageHeader, Panel } from '@/components/ui';
import { MARKET_NAV } from '@/lib/nav';
import { act } from '@/lib/act';
import { confirmDialog } from '@/lib/dialog';
import { BONUS_ORDER, DERIVED_LABELS, bonusLine, derivedDelta, formatDateTime, useTimezone } from '@/lib/format';
import type { Appearance, PremiumCategory, PremiumPiece } from '@/types';

interface StoreItem extends PremiumPiece {
    owned: boolean;
    worn: boolean;
    can_buy: boolean;
    buy_note: string | null;
    delta: Record<string, number>;
}

interface LedgerRow {
    id: number;
    actor: string;
    what: string;
    glyph: string;
    cash: string;
    cash_after: string;
    gold_delta: number;
    created_at: string;
}

interface Props {
    rowVersion: number;
    cash: number;
    cashLabel: string;
    startingCash: string;
    gold: number;
    power: number;
    premiumPower: number;
    categories: PremiumCategory[];
    items: StoreItem[];
    appearance: Appearance;
    bonus: Record<string, number>;
    name: string;
    level: number;
    ledger: LedgerRow[];
}

const CATEGORY_GLYPH: Record<string, string> = { title: '🏷️', wings: '🪽', aura: '✨', pet: '🐾', frame: '🖼️', bundle: '💰' };

function sortedBonus(bonus: Record<string, number>) {
    return Object.entries(bonus).sort(([a], [b]) => BONUS_ORDER.indexOf(a) - BONUS_ORDER.indexOf(b));
}

export default function Premium(props: Props) {
    const tz = useTimezone();
    const [tab, setTab] = useState<PremiumCategory>(props.categories[0] ?? 'title');
    const [busy, setBusy] = useState<string | null>(null);
    const shown = props.items.filter((item) => item.category === tab);
    const worn = props.appearance.gear;
    const bonus = sortedBonus(props.bonus);

    const send = (url: string, data: Record<string, string | number>, key: string) => {
        setBusy(key);
        act(url, { ...data, row_version: props.rowVersion }, { onFinish: () => setBusy(null) });
    };

    const buy = async (item: StoreItem) => {
        const ok = await confirmDialog({
            tone: 'gold',
            icon: <ShoppingCart className="size-8" />,
            title: `Buy ${item.name}?`,
            body: `${item.price}. Play money; nothing is charged.`,
            confirmLabel: 'Buy',
        });
        if (ok) send('/premium/buy', { code: item.code }, item.code);
    };

    return (
        <>
            <Head title="Premium Store" />
            <SectionNav entry={MARKET_NAV} />
            <PageHeader
                title="Premium Store"
                subtitle={<>Titles, wings, auras, pets, and frames. Every piece adds bonus stats. Play money only: every adventurer starts with {props.startingCash}.</>}
            >
                <span className="chip border-moss-500/50 bg-moss-500/10 px-3 py-1.5 text-base text-moss-400">
                    <Wallet className="size-4" /> {props.cashLabel}
                </span>
                <span className="chip border-ink-600 px-3 py-1.5 text-base"><Gold amount={props.gold} /></span>
            </PageHeader>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[20rem_1fr]">
                <div className="space-y-6">
                    <Panel title="Your look">
                        <div className="flex flex-col items-center gap-2 text-center">
                            <Portrait look={props.appearance} label={props.level} />
                            <div className="font-display text-xl font-bold text-ink-100">{props.name}</div>
                            <TitleBadge title={props.appearance.title} />
                            <div className="text-xs text-ink-400 tabular-nums">
                                Power {props.power}
                                {props.premiumPower > 0 && <span className="text-moss-400"> (+{props.premiumPower} premium)</span>}
                            </div>
                        </div>
                        <ul className="mt-4 space-y-1.5">
                            {props.categories.filter((c) => c !== 'bundle').map((category) => {
                                const piece = worn.find((g) => g.category === category);
                                return (
                                    <li key={category} className="flex items-center justify-between gap-2 rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-1.5 text-sm">
                                        <span className="text-ink-400">{CATEGORY_LABELS[category]}</span>
                                        {piece ? (
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span className={clsx('truncate font-semibold', styleOf(piece).text)}>
                                                    {piece.glyph} {piece.name}
                                                </span>
                                                <button
                                                    className="btn-ghost size-6 p-0"
                                                    disabled={busy !== null}
                                                    onClick={() => send('/premium/take-off', { category }, `off:${category}`)}
                                                    aria-label={`Take off ${piece.name}`}
                                                    title="Take off"
                                                >
                                                    <X className="size-3.5" />
                                                </button>
                                            </span>
                                        ) : (
                                            <span className="text-ink-500">empty</span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </Panel>

                    <Panel title={<><Sparkles className="mr-1 inline size-4" /> Premium bonus</>}>
                        {bonus.length === 0 ? (
                            <p className="text-sm text-ink-400">Wear a piece to add its bonus to every trip and arena bout.</p>
                        ) : (
                            <ul className="grid grid-cols-1 gap-1 text-sm">
                                {bonus.map(([key, value]) => (
                                    <li key={key} className="font-semibold text-moss-400">{bonusLine(key, value)}</li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="space-y-6">
                    <div className="flex gap-2 overflow-x-auto pb-1" role="tablist">
                        {props.categories.map((category) => (
                            <button
                                key={category}
                                role="tab"
                                aria-selected={tab === category}
                                onClick={() => setTab(category)}
                                className={clsx(
                                    'flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-semibold transition',
                                    tab === category
                                        ? 'border-lantern-400/60 bg-lantern-400/10 text-lantern-200'
                                        : 'border-ink-700 text-ink-300 hover:border-ink-500',
                                )}
                            >
                                <span aria-hidden>{CATEGORY_GLYPH[category]}</span>
                                {CATEGORY_LABELS[category]}
                            </button>
                        ))}
                    </div>

                    {shown.length === 0 ? (
                        <Empty icon="🛍️" title="Nothing here yet." />
                    ) : (
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                            {shown.map((item) => (
                                <StoreCard key={item.code} item={item} busy={busy} onBuy={() => buy(item)}
                                    onWear={() => send('/premium/wear', { code: item.code }, `wear:${item.code}`)}
                                    onTakeOff={() => send('/premium/take-off', { category: item.category }, `off:${item.category}`)}
                                />
                            ))}
                        </div>
                    )}

                    <Panel title="Purchase history">
                        {props.ledger.length === 0 ? (
                            <p className="text-sm text-ink-400">No purchases yet.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <tbody className="divide-y divide-ink-700/50">
                                    {props.ledger.map((row) => (
                                        <tr key={row.id}>
                                            <td className="py-1.5 text-ink-400 tabular-nums">{formatDateTime(row.created_at, tz)}</td>
                                            <td className="py-1.5 text-ink-100">{row.glyph} {row.what}</td>
                                            <td className="py-1.5 text-right text-blood-400 tabular-nums">−{row.cash}</td>
                                            <td className="py-1.5 text-right text-ink-400 tabular-nums">{row.cash_after} left</td>
                                            <td className="py-1.5 text-right">{row.gold_delta > 0 && <Gold amount={row.gold_delta} />}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

function StoreCard({ item, busy, onBuy, onWear, onTakeOff }: {
    item: StoreItem;
    busy: string | null;
    onBuy: () => void;
    onWear: () => void;
    onTakeOff: () => void;
}) {
    const s = styleOf(item);
    const tier = TIER[item.tier];
    const bundle = item.category === 'bundle';
    const deltas = Object.entries(item.delta);
    return (
        <div className={clsx('panel flex flex-col p-4 animate-rise', tier.ring, item.worn && s.glow)}>
            <div className="flex items-start gap-3">
                <div className={clsx('grid size-14 shrink-0 place-items-center rounded-xl border-2 bg-ink-950/70 text-3xl', s.border)}>
                    <span className={item.category === 'wings' ? 'animate-wing-right' : item.category === 'pet' ? 'animate-bob' : ''}>{item.glyph}</span>
                </div>
                <div className="min-w-0 flex-1">
                    <div className={clsx('font-display text-lg leading-tight font-bold', s.text)}>{item.name}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className={clsx('chip text-[10px]', tier.chip)}>{tier.label}</span>
                        {item.power > 0 && <span className="chip border-ink-600 text-[10px] text-ink-300">+{item.power} power</span>}
                    </div>
                </div>
            </div>
            {item.description && <p className="mt-3 text-xs text-ink-400 italic">{item.description}</p>}
            <ul className="mt-3 space-y-0.5 text-sm">
                {bundle ? (
                    <li className="flex items-center gap-1 font-semibold">+<Gold amount={item.grant_gold} /></li>
                ) : (
                    sortedBonus(item.bonuses).map(([key, value]) => (
                        <li key={key} className="font-semibold text-moss-400">{bonusLine(key, value)}</li>
                    ))
                )}
            </ul>
            {deltas.length > 0 && (
                <div className="mt-2 rounded-lg border border-ink-700/70 bg-ink-950/40 px-2.5 py-1.5 text-[11px] text-ink-400">
                    <span className="font-semibold text-ink-300">{item.owned ? 'If worn:' : 'Wearing it:'}</span>{' '}
                    {deltas.map(([key, value]) => (
                        <span key={key} className="mr-2 whitespace-nowrap">
                            <span className={value > 0 ? 'text-moss-400' : 'text-blood-400'}>{derivedDelta(key, value)}</span> {DERIVED_LABELS[key] ?? key}
                        </span>
                    ))}
                </div>
            )}
            <div className="mt-auto flex items-center justify-between gap-2 pt-4">
                <span className="font-display text-lg font-bold text-moss-400 tabular-nums">{item.owned && !bundle ? 'Owned' : item.price}</span>
                {item.worn ? (
                    <button className="btn-ghost" disabled={busy !== null} onClick={onTakeOff}>
                        <Check className="size-4 text-moss-400" /> Worn
                    </button>
                ) : item.owned && !bundle ? (
                    <button className="btn-primary" disabled={busy !== null} onClick={onWear}>
                        <Gem className="size-4" /> Wear
                    </button>
                ) : (
                    <button className="btn-primary" disabled={!item.can_buy || busy !== null} onClick={onBuy} title={item.buy_note ?? undefined}>
                        <ShoppingCart className="size-4" /> Buy
                    </button>
                )}
            </div>
            {!item.can_buy && !item.owned && item.buy_note && <p className="mt-1 text-[11px] text-blood-400">{item.buy_note}</p>}
        </div>
    );
}
