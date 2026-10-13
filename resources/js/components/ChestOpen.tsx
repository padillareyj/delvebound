import { usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { useEffect, useState } from 'react';
import { RarityName, slotGlyph } from '@/components/ItemCard';
import { Gold } from '@/components/ui';
import { RARITY_BORDER, RARITY_GLOW } from '@/lib/format';
import type { OpenedChest, SharedProps } from '@/types';

const RARITY_RANK = ['common', 'uncommon', 'rare', 'epic', 'legendary'] as const;

/** Plays once, from the flash payload of a successful open. The items are already in the pack underneath. */
export default function ChestOpen() {
    const chest = usePage<SharedProps>().props.flash.openedChest;
    const [phase, setPhase] = useState<'shake' | 'reveal' | 'done'>('shake');
    const [shown, setShown] = useState(0);
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => {
        if (!chest) return;
        setDismissed(false);
        setPhase('shake');
        setShown(0);
        const burst = window.setTimeout(() => setPhase('reveal'), 1400);
        return () => window.clearTimeout(burst);
    }, [chest]);

    useEffect(() => {
        if (!chest || phase !== 'reveal') return;
        if (shown >= chest.items.length) {
            const done = window.setTimeout(() => setPhase('done'), 280);
            return () => window.clearTimeout(done);
        }
        const next = window.setTimeout(() => setShown((n) => n + 1), shown === 0 ? 420 : 520);
        return () => window.clearTimeout(next);
    }, [chest, phase, shown]);

    if (!chest || dismissed) return null;

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-veil/75 p-4 backdrop-blur-sm">
            <div className="w-full max-w-md text-center">
                {phase === 'shake' ? (
                    <ChestGlyph shaking place={chest.place} />
                ) : (
                    <Reveal chest={chest} shown={shown} ready={phase === 'done'} onClose={() => setDismissed(true)} />
                )}
            </div>
        </div>
    );
}

function ChestGlyph({ shaking, place }: { shaking?: boolean; place: string }) {
    return (
        <div className={clsx(shaking && 'animate-chest-shake')}>
            <div className="text-8xl drop-shadow-[0_12px_24px_rgb(0_0_0/0.6)]">🧰</div>
            <div className="mt-4 font-display text-xl font-bold text-lantern-200">{place}</div>
            <p className="mt-1 text-sm text-ink-300">The latch rattles…</p>
        </div>
    );
}

function Reveal({ chest, shown, ready, onClose }: { chest: OpenedChest; shown: number; ready: boolean; onClose: () => void }) {
    const best = chest.items.reduce((top, item) => (RARITY_RANK.indexOf(item.rarity) > RARITY_RANK.indexOf(top) ? item.rarity : top), 'common' as (typeof RARITY_RANK)[number]);

    return (
        <div className="animate-chest-burst">
            <div className={clsx('mx-auto mb-4 grid size-24 place-items-center rounded-full text-5xl', RARITY_GLOW[best] ?? '')}>✨</div>
            <div className="font-display text-2xl font-extrabold text-lantern-200">{chest.place}</div>
            <ul className="mt-5 space-y-2 text-left">
                {chest.items.slice(0, shown).map((item) => (
                    <li
                        key={item.id}
                        className={clsx(
                            'flex animate-rise items-center gap-3 rounded-xl border bg-ink-900/80 px-3 py-2',
                            RARITY_BORDER[item.rarity],
                            RARITY_GLOW[item.rarity],
                        )}
                    >
                        <span className="text-ink-100">{slotGlyph(item.slot, 'size-8')}</span>
                        <span className="min-w-0 flex-1">
                            <RarityName name={item.name} rarity={item.rarity} className="block truncate font-semibold" />
                            <span className="text-[11px] tracking-wide text-ink-400 uppercase">ilvl {item.item_level}</span>
                        </span>
                    </li>
                ))}
                {shown === 0 && <li className="py-6 text-center text-sm text-ink-400">Dust and nothing else.</li>}
            </ul>
            {ready && chest.sold.length > 0 && (
                <ul className="mt-3 space-y-1 text-left text-xs text-ink-400">
                    {chest.sold.map((row, i) => (
                        <li key={i}>Pack full — sold {row.name} for <Gold amount={row.sell_value} />.</li>
                    ))}
                </ul>
            )}
            {ready && (
                <button className="btn-primary mt-5 w-full" onClick={onClose}>
                    Into the pack
                </button>
            )}
        </div>
    );
}
