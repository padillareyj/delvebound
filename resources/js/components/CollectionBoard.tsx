import { Link } from '@inertiajs/react';
import clsx from 'clsx';
import { Check, Gem, Lock, Power, Sparkles, X, Zap } from 'lucide-react';
import { useState } from 'react';
import { CATEGORY_LABELS, Portrait, TIER, TitleBadge, styleOf } from '@/components/Premium';
import { Empty, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { BONUS_ORDER, bonusLine, money } from '@/lib/format';
import type { Appearance, Collection, CollectionPiece } from '@/types';

const CATEGORIES = ['title', 'wings', 'aura', 'pet', 'frame'] as const;
const CATEGORY_GLYPH: Record<string, string> = { title: '🏷️', wings: '🪽', aura: '✨', pet: '🐾', frame: '🖼️' };

function sortedBonus(bonus: Record<string, number>) {
    return Object.entries(bonus).sort(([a], [b]) => BONUS_ORDER.indexOf(a) - BONUS_ORDER.indexOf(b));
}

/** Hero → Collection: every premium piece, owned or not. Active pieces add stats; one active piece per category is worn. */
export default function CollectionBoard({ collection, appearance, level, name, cash, rowVersion }: {
    collection: Collection;
    appearance: Appearance;
    level: number;
    name: string;
    cash: number;
    rowVersion: number;
}) {
    const [tab, setTab] = useState<(typeof CATEGORIES)[number]>('title');
    const [ownedOnly, setOwnedOnly] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const send = (url: string, data: Record<string, string | number | boolean>, key: string) => {
        setBusy(key);
        act(url, { ...data, row_version: rowVersion }, { onFinish: () => setBusy(null) });
    };

    const owned = collection.pieces.filter((p) => p.owned);
    const inactive = owned.filter((p) => !p.active).length;
    const shown = collection.pieces
        .filter((p) => p.category === tab && (!ownedOnly || p.owned))
        .sort((a, b) => Number(b.owned) - Number(a.owned));
    const bonus = sortedBonus(collection.bonus);

    return (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-[20rem_1fr]">
            <div className="space-y-6">
                <Panel title="Your look">
                    <div className="flex flex-col items-center gap-2 text-center">
                        <Portrait look={appearance} label={level} />
                        <div className="font-display text-xl font-bold text-ink-100">{name}</div>
                        <TitleBadge title={appearance.title} />
                    </div>
                    <ul className="mt-4 space-y-1.5">
                        {CATEGORIES.map((category) => {
                            const piece = appearance.gear.find((g) => g.category === category);
                            return (
                                <li key={category} className="flex items-center justify-between gap-2 rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-1.5 text-sm">
                                    <span className="text-ink-400">{CATEGORY_LABELS[category]}</span>
                                    {piece ? (
                                        <span className="flex min-w-0 items-center gap-2">
                                            <span className={clsx('truncate font-semibold', styleOf(piece).text)}>{piece.glyph} {piece.name}</span>
                                            <button
                                                className="btn-ghost size-6 p-0"
                                                disabled={busy !== null}
                                                onClick={() => send('/premium/take-off', { category }, `off:${category}`)}
                                                aria-label={`Unequip ${piece.name}`}
                                                title="Unequip (stays active)"
                                            >
                                                <X className="size-3.5" />
                                            </button>
                                        </span>
                                    ) : (
                                        <span className="text-ink-500">none</span>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </Panel>

                <Panel
                    title={<><Sparkles className="mr-1 inline size-4" /> Collection bonus</>}
                    action={<span className="text-xs text-ink-400 tabular-nums">+{collection.power} power</span>}
                >
                    <p className="mb-2 text-xs text-ink-400">
                        {owned.length - inactive} of {owned.length} owned pieces active. Every active piece adds its stats, worn or not.
                    </p>
                    {bonus.length === 0 ? (
                        <p className="text-sm text-ink-400">Activate a piece to add its bonus to every trip and arena bout.</p>
                    ) : (
                        <ul className="grid grid-cols-1 gap-1 text-sm">
                            {bonus.map(([key, value]) => (
                                <li key={key} className="font-semibold text-moss-400">{bonusLine(key, value)}</li>
                            ))}
                        </ul>
                    )}
                    {inactive > 0 && (
                        <button className="btn-primary mt-3 w-full justify-center" disabled={busy !== null} onClick={() => send('/premium/activate-all', {}, 'all')}>
                            <Zap className="size-4" /> Activate all ({inactive})
                        </button>
                    )}
                    <Link href="/premium" className="btn-ghost mt-2 w-full justify-center">
                        <Gem className="size-4" /> Premium store · {money(cash)}
                    </Link>
                </Panel>
            </div>

            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex gap-2 overflow-x-auto pb-1" role="tablist">
                        {CATEGORIES.map((category) => {
                            const all = collection.pieces.filter((p) => p.category === category);
                            const have = all.filter((p) => p.owned).length;
                            return (
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
                                    <span className="text-[11px] font-normal text-ink-400 tabular-nums">{have}/{all.length}</span>
                                </button>
                            );
                        })}
                    </div>
                    <label className="ml-auto flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" checked={ownedOnly} onChange={(e) => setOwnedOnly(e.target.checked)} />
                        Owned only
                    </label>
                </div>

                {shown.length === 0 ? (
                    <Empty icon={CATEGORY_GLYPH[tab]} title={ownedOnly ? `No ${CATEGORY_LABELS[tab].toLowerCase()} yet.` : 'Nothing here yet.'}>
                        <Link href="/premium" className="btn-primary mt-3"><Gem className="size-4" /> Visit the store</Link>
                    </Empty>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                        {shown.map((piece) => (
                            <PieceCard
                                key={piece.code}
                                piece={piece}
                                busy={busy}
                                onActive={(on) => send('/premium/activate', { code: piece.code, active: on }, `act:${piece.code}`)}
                                onWear={() => send('/premium/wear', { code: piece.code }, `wear:${piece.code}`)}
                                onTakeOff={() => send('/premium/take-off', { category: piece.category }, `off:${piece.category}`)}
                            />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function PieceCard({ piece, busy, onActive, onWear, onTakeOff }: {
    piece: CollectionPiece;
    busy: string | null;
    onActive: (on: boolean) => void;
    onWear: () => void;
    onTakeOff: () => void;
}) {
    const s = styleOf(piece);
    const tier = TIER[piece.tier];
    return (
        <div className={clsx('panel flex flex-col p-4', piece.owned ? tier.ring : 'opacity-55', piece.worn && s.glow)}>
            <div className="flex items-start gap-3">
                <div className={clsx('relative grid size-14 shrink-0 place-items-center rounded-xl border-2 bg-ink-950/70 text-3xl', piece.owned ? s.border : 'border-ink-700 grayscale')}>
                    <span className={piece.owned && piece.category === 'wings' ? 'animate-wing-right' : piece.owned && piece.category === 'pet' ? 'animate-bob' : ''}>{piece.glyph}</span>
                    {!piece.owned && <Lock className="absolute -right-1.5 -bottom-1.5 size-4 rounded bg-ink-900 p-0.5 text-ink-400" />}
                </div>
                <div className="min-w-0 flex-1">
                    <div className={clsx('font-display text-lg leading-tight font-bold', piece.owned ? s.text : 'text-ink-300')}>{piece.name}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className={clsx('chip text-[10px]', tier.chip)}>{tier.label}</span>
                        {piece.power > 0 && <span className="chip border-ink-600 text-[10px] text-ink-300">+{piece.power} power</span>}
                        {piece.active && <span className="chip border-moss-500/50 bg-moss-500/10 text-[10px] text-moss-400">Active</span>}
                        {piece.worn && <span className="chip border-lantern-400/50 bg-lantern-400/10 text-[10px] text-lantern-200">Equipped</span>}
                    </div>
                </div>
            </div>
            <ul className="mt-3 space-y-0.5 text-sm">
                {sortedBonus(piece.bonuses).map(([key, value]) => (
                    <li key={key} className={clsx('font-semibold', piece.active ? 'text-moss-400' : 'text-ink-400')}>{bonusLine(key, value)}</li>
                ))}
            </ul>
            <div className="mt-auto flex flex-wrap items-center justify-end gap-2 pt-4">
                {!piece.owned ? (
                    <Link href="/premium" className="btn-ghost"><Gem className="size-4" /> {piece.price}</Link>
                ) : (
                    <>
                        <button
                            className={piece.active ? 'btn-ghost' : 'btn-primary'}
                            disabled={busy !== null}
                            onClick={() => onActive(!piece.active)}
                            title={piece.active ? 'Turn its stats off (also unequips it)' : 'Turn its stats on'}
                        >
                            <Power className="size-4" /> {piece.active ? 'Deactivate' : 'Activate'}
                        </button>
                        {piece.worn ? (
                            <button className="btn-ghost" disabled={busy !== null} onClick={onTakeOff} title="Unequip (stays active)">
                                <Check className="size-4 text-moss-400" /> Equipped
                            </button>
                        ) : (
                            <button className="btn-primary" disabled={busy !== null} onClick={onWear} title="Show it on your portrait (activates it too)">
                                <Sparkles className="size-4" /> Equip
                            </button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
