import clsx from 'clsx';
import type { Appearance, PremiumPiece, PremiumTier } from '@/types';

export const STYLE: Record<string, { text: string; border: string; glow: string; aura: string }> = {
    lantern: { text: 'text-lantern-300', border: 'border-lantern-400/70', glow: 'shadow-[0_0_22px_-4px_rgb(245_180_83/0.7)]', aura: 'from-lantern-400/60' },
    ember: { text: 'text-orange-400', border: 'border-orange-500/70', glow: 'shadow-[0_0_22px_-4px_rgb(249_115_22/0.75)]', aura: 'from-orange-500/60' },
    frost: { text: 'text-frost-400', border: 'border-frost-400/70', glow: 'shadow-[0_0_22px_-4px_rgb(108_198_240/0.75)]', aura: 'from-frost-400/55' },
    moss: { text: 'text-moss-400', border: 'border-moss-500/70', glow: 'shadow-[0_0_22px_-4px_rgb(76_184_101/0.7)]', aura: 'from-moss-500/55' },
    blood: { text: 'text-blood-400', border: 'border-blood-500/70', glow: 'shadow-[0_0_22px_-4px_rgb(220_63_63/0.75)]', aura: 'from-blood-500/55' },
    arcane: { text: 'text-arcane-400', border: 'border-arcane-400/70', glow: 'shadow-[0_0_22px_-4px_rgb(157_140_255/0.75)]', aura: 'from-arcane-500/60' },
    void: { text: 'text-violet-300', border: 'border-violet-500/70', glow: 'shadow-[0_0_26px_-4px_rgb(139_92_246/0.85)]', aura: 'from-violet-600/65' },
    celestial: { text: 'text-amber-100', border: 'border-amber-100/80', glow: 'shadow-[0_0_28px_-4px_rgb(254_243_199/0.8)]', aura: 'from-amber-100/50' },
};

export const TIER: Record<PremiumTier, { label: string; chip: string; ring: string }> = {
    rare: { label: 'Rare', chip: 'border-rarity-rare/50 bg-rarity-rare/10 text-rarity-rare', ring: 'border-rarity-rare/40' },
    epic: { label: 'Epic', chip: 'border-rarity-epic/50 bg-rarity-epic/10 text-rarity-epic', ring: 'border-rarity-epic/45' },
    legendary: { label: 'Legendary', chip: 'border-rarity-legendary/60 bg-rarity-legendary/10 text-rarity-legendary', ring: 'border-rarity-legendary/55' },
    mythic: {
        label: 'Mythic',
        chip: 'border-rose-400/60 bg-gradient-to-r from-rose-500/20 via-fuchsia-500/20 to-amber-400/20 text-rose-200',
        ring: 'border-rose-400/60',
    },
};

export const CATEGORY_LABELS: Record<string, string> = {
    title: 'Titles',
    wings: 'Wings',
    aura: 'Auras',
    pet: 'Pets',
    frame: 'Frames',
    bundle: 'Gold bundles',
};

export function styleOf(piece: { style: string } | null | undefined) {
    return STYLE[piece?.style ?? 'lantern'] ?? STYLE.lantern;
}

export function TitleBadge({ title, className }: { title: PremiumPiece | null | undefined; className?: string }) {
    if (!title) return null;
    const s = styleOf(title);
    return (
        <span
            className={clsx('inline-flex items-center gap-1 rounded-md border px-1.5 py-px text-[10px] font-bold uppercase tracking-wider', s.border, s.text, 'bg-ink-950/60', className)}
            title={`Title: ${title.name}`}
        >
            <span aria-hidden>{title.glyph}</span>
            {title.name}
        </span>
    );
}

function piece(look: Appearance | null | undefined, category: string): PremiumPiece | undefined {
    return look?.gear.find((g) => g.category === category);
}

/** The hero portrait with every worn cosmetic: frame border, aura glow, wings on both sides, pet at the foot. */
export function Portrait({ look, label, size = 'lg', className }: {
    look: Appearance | null | undefined;
    label: string | number;
    size?: 'sm' | 'lg';
    className?: string;
}) {
    const frame = piece(look, 'frame');
    const aura = piece(look, 'aura');
    const wings = piece(look, 'wings');
    const pet = piece(look, 'pet');
    const big = size === 'lg';
    const fs = styleOf(frame);
    return (
        <div className={clsx('relative grid place-items-center', big ? 'h-36 w-56' : 'h-12 w-20', className)}>
            {aura && (
                <div
                    className={clsx(
                        'absolute rounded-full bg-radial to-transparent to-70% animate-aura blur-md',
                        styleOf(aura).aura,
                        big ? 'size-36' : 'size-12',
                    )}
                    aria-hidden
                />
            )}
            {wings && (
                <>
                    <span
                        className={clsx('absolute top-1/2 origin-center -translate-y-1/2 animate-wing-left select-none', big ? 'left-1 text-5xl' : 'left-0 text-lg')}
                        aria-hidden
                    >
                        {wings.glyph}
                    </span>
                    <span
                        className={clsx('absolute top-1/2 origin-left -translate-y-1/2 animate-wing-right select-none', big ? 'right-1 text-5xl' : 'right-0 text-lg')}
                        aria-hidden
                    >
                        {wings.glyph}
                    </span>
                </>
            )}
            <div
                className={clsx(
                    'relative grid place-items-center rounded-full bg-ink-800 font-display font-extrabold text-lantern-300',
                    big ? 'size-24 border-4 text-4xl' : 'size-10 border-2 text-lg',
                    frame ? [fs.border, fs.glow] : 'border-lantern-400/50',
                )}
            >
                {label}
                {frame && big && (
                    <span className="absolute -top-3 left-1/2 -translate-x-1/2 text-base" aria-hidden>
                        {frame.glyph}
                    </span>
                )}
            </div>
            {pet && (
                <span className={clsx('absolute animate-bob select-none', big ? 'right-10 bottom-0 text-3xl' : '-right-0.5 -bottom-1 text-sm')} aria-hidden>
                    {pet.glyph}
                </span>
            )}
        </div>
    );
}

/** Small glyph strip for lists: wings, aura, pet, frame. */
export function GearGlyphs({ look, glyphs }: { look?: Appearance | null; glyphs?: string[] }) {
    const list = glyphs ?? (look?.gear ?? []).filter((g) => g.category !== 'title').map((g) => g.glyph);
    if (list.length === 0) return null;
    return (
        <span className="inline-flex gap-0.5 text-xs" aria-label="Premium pieces">
            {list.map((g, i) => (
                <span key={i}>{g}</span>
            ))}
        </span>
    );
}
