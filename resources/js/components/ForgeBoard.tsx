import { router, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { Flame, Hammer, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { GearReadout, RarityName } from '@/components/ItemCard';
import { ItemGlyph } from '@/components/ItemGlyph';
import { Gold, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { alertDialog, confirmDialog, type DialogOptions } from '@/lib/dialog';
import { RARITY_BORDER, RARITY_TEXT, SLOT_LABELS } from '@/lib/format';
import type { ForgeQuote, ForgeResult, PackItem, SharedProps } from '@/types';

interface Props {
    rowVersion: number;
    gold: number;
    inventory: PackItem[];
    forgeQuote: ForgeQuote | null;
}

type Pair = [number | null, number | null];

function idsFromUrl(url: string): Pair {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const params = new URLSearchParams(query);
    const read = (key: string) => {
        const value = Number(params.get(key));
        return Number.isInteger(value) && value > 0 ? value : null;
    };
    return [read('forge_a'), read('forge_b')];
}

const RELOAD = { only: ['forgeQuote'], preserveState: true, preserveScroll: true, replace: true };

function resultDialog(result: ForgeResult): DialogOptions {
    const spent = <span className="mt-2 block text-xs text-ink-400">Spent <Gold amount={result.gold} /></span>;
    if (result.outcome === 'fail' || !result.item) {
        return {
            tone: 'danger',
            icon: <Flame className="size-8" />,
            title: 'The forge cracks',
            body: <>Both pieces are lost to the fire.{spent}</>,
            confirmLabel: 'Sweep up the ashes',
        };
    }
    const item = result.item;
    const masterpiece = result.outcome === 'masterpiece';
    return {
        tone: masterpiece ? 'gold' : 'success',
        icon: <ItemGlyph slot={item.slot} className={clsx('size-9', RARITY_TEXT[item.rarity])} />,
        title: masterpiece ? 'The forge sings!' : 'The forge yields',
        body: (
            <>
                <RarityName name={item.name} rarity={item.rarity} className="block text-base" />
                <span className="block text-[11px] tracking-wide text-ink-400 uppercase">{item.rarity} · ilvl {item.item_level}</span>
                {spent}
            </>
        ),
        confirmLabel: 'Into the pack',
    };
}

/** Swings the hammer for a moment after a strike, then opens the result dialog. */
function useForgeReveal() {
    const forged = usePage<SharedProps>().props.flash.forged;
    const [striking, setStriking] = useState(false);

    useEffect(() => {
        if (!forged) return;
        setStriking(true);
        const reveal = window.setTimeout(() => {
            setStriking(false);
            alertDialog(resultDialog(forged));
        }, 1500);
        return () => window.clearTimeout(reveal);
    }, [forged]);

    return striking;
}

export default function ForgeBoard({ rowVersion, gold, inventory, forgeQuote }: Props) {
    const pageUrl = usePage().url;
    const [pair, setPair] = useState<Pair>(() => idsFromUrl(pageUrl));
    const [picking, setPicking] = useState<0 | 1 | null>(null);
    const striking = useForgeReveal();
    const [left, right] = pair;
    const pieces = pair.map((id) => inventory.find((item) => item.id === id) ?? null);

    const quoteMatches = forgeQuote !== null && left !== null && right !== null
        && ((forgeQuote.left_id === left && forgeQuote.right_id === right)
            || (forgeQuote.left_id === right && forgeQuote.right_id === left));
    const quote = quoteMatches ? forgeQuote : null;

    useEffect(() => {
        if (left === null || right === null || quoteMatches) return;
        router.get('/character', { tab: 'forge', forge_a: left, forge_b: right }, RELOAD);
    }, [left, right, quoteMatches]);

    const setSlot = (index: 0 | 1, id: number | null) => {
        const next: Pair = [...pair];
        next[index] = id;
        setPair(next);
        if ((next[0] === null || next[1] === null) && left !== null && right !== null) {
            router.get('/character', { tab: 'forge' }, RELOAD);
        }
    };

    const other = picking === null ? null : pieces[picking === 0 ? 1 : 0];
    const choices = picking === null ? [] : inventory.filter((item) =>
        item.id !== left && item.id !== right && (other === null || item.template_slot === other.template_slot));

    return (
        <div className="space-y-6">
            <Panel
                title="Forge"
                action={quote && quote.gold > 0 ? <span className="flex items-center gap-1 text-xs text-ink-400">Cost <Gold amount={quote.gold} /></span> : <Hammer className="size-4 text-lantern-300" />}
            >
                <p className="text-sm text-ink-300">
                    Click an empty slot and choose a spare piece. Both pieces must be the same slot. The forge spends the
                    gold and consumes both: it can crack, yield a new piece of the better rarity, or sing and hand you
                    something finer.
                </p>

                <div className="mt-4 grid grid-cols-1 items-center gap-3 sm:grid-cols-[1fr_auto_1fr]">
                    {[0, 1].map((index) => {
                        const piece = pieces[index];
                        const active = picking === index;
                        return (
                            <div key={index} className={clsx(index === 1 && 'sm:order-3')}>
                                {piece ? (
                                    <div className={clsx('relative rounded-xl border bg-ink-950/70 p-3', RARITY_BORDER[piece.rarity])}>
                                        <button
                                            type="button"
                                            className="absolute top-2 right-2 text-ink-400 hover:text-ink-100"
                                            aria-label="Take it off the forge"
                                            onClick={() => setSlot(index as 0 | 1, null)}
                                        >
                                            <X className="size-4" />
                                        </button>
                                        <GearReadout item={piece} />
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => setPicking(active ? null : (index as 0 | 1))}
                                        className={clsx(
                                            'grid h-36 w-full place-items-center rounded-xl border-2 border-dashed bg-ink-950/60 text-ink-400 transition hover:border-lantern-400/60 hover:text-lantern-200',
                                            active ? 'border-lantern-400 text-lantern-200' : 'border-ink-700',
                                        )}
                                    >
                                        <span className="flex flex-col items-center gap-1 text-xs">
                                            <Plus className="size-6" />
                                            {active ? 'Choose a piece below' : 'Select an item'}
                                        </span>
                                    </button>
                                )}
                            </div>
                        );
                    })}
                    <div className="flex justify-center sm:order-2">
                        <span className="grid size-12 place-items-center rounded-full border border-lantern-500/40 bg-ink-900 text-lantern-300">
                            <Hammer className="size-5" />
                        </span>
                    </div>
                </div>

                {picking !== null && (
                    <div className="mt-4 rounded-xl border border-ink-700 bg-ink-950/50 p-3">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-xs text-ink-300">
                                {other ? `Spare ${SLOT_LABELS[other.template_slot] ?? other.template_slot} pieces` : 'Spare pieces in your inventory'}
                            </span>
                            <button type="button" className="text-xs text-ink-400 hover:text-ink-100" onClick={() => setPicking(null)}>Cancel</button>
                        </div>
                        {choices.length === 0 ? (
                            <p className="text-sm text-ink-400">Nothing in your inventory fits this slot.</p>
                        ) : (
                            <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {choices.map((item) => (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            onClick={() => { setSlot(picking, item.id); setPicking(null); }}
                                            className={clsx('flex w-full items-center gap-2 rounded-lg border bg-ink-900/60 px-2 py-1.5 text-left hover:brightness-125', RARITY_BORDER[item.rarity])}
                                        >
                                            <ItemGlyph slot={item.template_slot} itemType={item.item_type} twoHanded={item.two_handed} className={clsx('size-7 shrink-0', RARITY_TEXT[item.rarity])} />
                                            <span className="min-w-0">
                                                <span className={clsx('block truncate text-sm font-semibold', RARITY_TEXT[item.rarity])}>{item.name}</span>
                                                <span className="block text-[11px] capitalize text-ink-400">
                                                    {item.rarity} · {SLOT_LABELS[item.template_slot] ?? item.template_slot} · ilvl {item.item_level}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {quote?.chances && (
                    <ul className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                        <li className="rounded-lg border border-blood-500/30 bg-ink-950/60 px-2 py-2">
                            <div className="text-lg font-bold tabular-nums text-blood-400">{quote.chances.fail}%</div>
                            <div className="text-ink-400">Crack</div>
                        </li>
                        <li className="rounded-lg border border-ink-700 bg-ink-950/60 px-2 py-2">
                            <div className="text-lg font-bold tabular-nums text-ink-100">{quote.chances.craft}%</div>
                            <div className="text-ink-400">Yield</div>
                        </li>
                        <li className="rounded-lg border border-lantern-500/40 bg-ink-950/60 px-2 py-2">
                            <div className="text-lg font-bold tabular-nums text-lantern-200">{quote.chances.masterpiece}%</div>
                            <div className="text-ink-400">Sing</div>
                        </li>
                    </ul>
                )}
                {quote && !quote.ok && quote.reason && <p className="mt-2 text-xs text-blood-400">{quote.reason}</p>}

                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        className="btn-primary"
                        disabled={!quote?.ok}
                        onClick={async () => {
                            if (!quote?.ok || left === null || right === null) return;
                            const ok = await confirmDialog({
                                icon: <Hammer className="size-8" />,
                                title: 'Strike the forge?',
                                body: (
                                    <>
                                        Both pieces go into the fire and the attempt can crack.
                                        <span className="mt-2 flex items-center justify-center gap-1">Cost <Gold amount={quote.gold} /></span>
                                    </>
                                ),
                                confirmLabel: 'Strike',
                                cancelLabel: 'Not yet',
                            });
                            if (!ok) return;
                            act('/pack/forge', { item_a: left, item_b: right, row_version: rowVersion }, {
                                onFinish: () => {
                                    setPair([null, null]);
                                    router.get('/character', { tab: 'forge' }, RELOAD);
                                },
                            });
                        }}
                    >
                        <Hammer className="size-4" /> Strike the forge
                    </button>
                    <span className="text-xs text-ink-400">You have <Gold amount={gold} /></span>
                </div>
            </Panel>

            {striking && (
                <div className="fixed inset-0 z-[60] grid animate-fade-in place-items-center bg-ink-950/80 backdrop-blur-sm">
                    <div className="text-center">
                        <Hammer className="mx-auto size-24 origin-bottom-right animate-hammer text-lantern-300 drop-shadow-[0_0_24px_var(--color-lantern-500)]" />
                        <p className="mt-4 font-display text-lg text-lantern-200">The hammer falls…</p>
                    </div>
                </div>
            )}
        </div>
    );
}
