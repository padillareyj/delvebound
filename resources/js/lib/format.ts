import { usePage } from '@inertiajs/react';
import type { Outcome, Rarity, SharedProps, StatKey } from '@/types';

export function useTimezone(): string {
    return usePage<SharedProps>().props.displayTimezone || 'Asia/Bangkok';
}

export function formatDateTime(iso: string | null | undefined, tz: string): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: tz,
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

export function formatDate(iso: string | null | undefined, tz: string): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', { timeZone: tz, day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso));
}

export function timeAgo(iso: string | null | undefined): string {
    if (!iso) return '—';
    const secs = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (secs < 60) return 'just now';
    const units: [number, string][] = [
        [60, 'minute'],
        [3600, 'hour'],
        [86400, 'day'],
        [604800, 'week'],
    ];
    let label = '';
    for (const [size, name] of units) {
        if (secs >= size) {
            const n = Math.floor(secs / size);
            label = `${n} ${name}${n === 1 ? '' : 's'} ago`;
        }
    }
    return label;
}

export const num = (n: number) => new Intl.NumberFormat('en-US').format(n);

export function signed(n: number, digits = 0): string {
    const fixed = Number.isInteger(n) && digits === 0 ? String(n) : n.toFixed(digits || 1);
    return n > 0 ? `+${fixed}` : fixed;
}

export const STAT_NAMES: Record<StatKey, { short: string; name: string; blurb: string }> = {
    str: { short: 'STR', name: 'Strength', blurb: 'Power for strength weapons, a little HP.' },
    dex: { short: 'DEX', name: 'Dexterity', blurb: 'Power for bows and daggers, crit, dodge, speed.' },
    int: { short: 'INT', name: 'Intellect', blurb: 'Power for staves and wands, magic resistance.' },
    vit: { short: 'VIT', name: 'Vitality', blurb: 'Max HP, defense, and regeneration between fights.' },
    luk: { short: 'LUK', name: 'Luck', blurb: 'Better loot, more gold, a touch of crit.' },
};

export const DERIVED_LABELS: Record<string, string> = {
    hp: 'Max HP',
    attack: 'Attack',
    defense: 'Defense',
    mres: 'Magic Res',
    crit: 'Crit',
    crit_mult: 'Crit Damage',
    dodge: 'Dodge',
    speed: 'Speed',
    regen: 'Regen',
    luck: 'Luck',
    gold_find: 'Gold Find',
};

export const PERCENT_KEYS = new Set(['crit', 'dodge', 'regen']);

export function derivedValue(key: string, value: number): string {
    if (PERCENT_KEYS.has(key)) return `${round1(value)}%`;
    if (key === 'luck') return `${Math.round(value * 100)}%`;
    if (key === 'gold_find' || key === 'crit_mult') return `×${value.toFixed(2)}`;
    return num(Math.round(value));
}

export function derivedDelta(key: string, value: number): string {
    if (PERCENT_KEYS.has(key)) return `${value > 0 ? '+' : ''}${round1(value)}%`;
    if (key === 'luck') return `${value > 0 ? '+' : ''}${round1(value * 100)}%`;
    if (key === 'gold_find' || key === 'crit_mult') return `${value > 0 ? '+' : ''}${value.toFixed(2)}`;
    return signed(Math.round(value * 10) / 10);
}

const round1 = (v: number) => (Math.round(v * 10) / 10).toString();

const MOD_LABELS: Record<string, string> = {
    armor: 'Armor',
    attack: 'Attack',
    hp: 'Max HP',
    crit_pct: 'Crit',
    speed: 'Speed',
    mres: 'Magic Res',
    crit_dmg_pct: 'Crit Damage',
    str: 'Strength',
    dex: 'Dexterity',
    int: 'Intellect',
    vit: 'Vitality',
    luk: 'Luck',
    dodge_pct: 'Dodge',
    gold_find_pct: 'Gold Find',
    magic_find_pct: 'Magic Find',
};

export function modLine(key: string, value: number): string {
    const pct = key.endsWith('_pct');
    return `${value > 0 ? '+' : ''}${value}${pct ? '%' : ''} ${MOD_LABELS[key] ?? key}`;
}

export const SLOT_LABELS: Record<string, string> = {
    weapon: 'Weapon',
    offhand: 'Off-hand',
    head: 'Head',
    chest: 'Chest',
    hands: 'Hands',
    feet: 'Feet',
    ring: 'Ring',
    ring1: 'Ring I',
    ring2: 'Ring II',
    amulet: 'Amulet',
};

export const RARITY_TEXT: Record<Rarity, string> = {
    common: 'text-rarity-common',
    uncommon: 'text-rarity-uncommon',
    rare: 'text-rarity-rare',
    epic: 'text-rarity-epic',
    legendary: 'text-rarity-legendary',
};

export const RARITY_BORDER: Record<Rarity, string> = {
    common: 'border-ink-600',
    uncommon: 'border-rarity-uncommon/50',
    rare: 'border-rarity-rare/50',
    epic: 'border-rarity-epic/55',
    legendary: 'border-rarity-legendary/60',
};

export const RARITY_GLOW: Record<Rarity, string> = {
    common: '',
    uncommon: 'shadow-[0_0_18px_-8px_var(--color-rarity-uncommon)]',
    rare: 'shadow-[0_0_20px_-8px_var(--color-rarity-rare)]',
    epic: 'shadow-[0_0_22px_-6px_var(--color-rarity-epic)]',
    legendary: 'shadow-[0_0_26px_-6px_var(--color-rarity-legendary)]',
};

export const OUTCOME_META: Record<Outcome, { label: string; chip: string; tone: string }> = {
    cleared: { label: 'Cleared', chip: 'border-moss-500/50 bg-moss-500/10 text-moss-400', tone: 'text-moss-400' },
    retreated: { label: 'Retreated', chip: 'border-lantern-500/50 bg-lantern-500/10 text-lantern-300', tone: 'text-lantern-300' },
    defeated: { label: 'Defeated', chip: 'border-blood-500/50 bg-blood-500/10 text-blood-400', tone: 'text-blood-400' },
    rested: { label: 'Rested', chip: 'border-frost-400/40 bg-frost-400/10 text-frost-400', tone: 'text-frost-400' },
    blocked_injured: { label: 'Injured', chip: 'border-blood-600/50 bg-blood-600/10 text-blood-400', tone: 'text-blood-400' },
    order_rejected: { label: 'Rejected', chip: 'border-ink-500 bg-ink-700/40 text-ink-200', tone: 'text-ink-200' },
    champion: { label: 'Champion', chip: 'border-lantern-400/60 bg-lantern-400/15 text-lantern-200', tone: 'text-lantern-200' },
    eliminated: { label: 'Eliminated', chip: 'border-blood-500/50 bg-blood-500/10 text-blood-400', tone: 'text-blood-400' },
};

export const INJURY_LABELS = ['Healthy', 'Wounded', 'Badly wounded'];

export const PLACE_ICONS: Record<string, string> = {
    rest: '🏮',
    forest: '🌲',
    mine: '⛏️',
    dungeon: '💀',
    mountain: '🏔️',
    cave: '🌋',
    marsh: '🪷',
    coast: '⚓',
    ruins: '🏚️',
    temple: '🕯️',
};

export function effectLine(effect: string | null, value: number | null, trigger?: string | null): string {
    if (!effect) return '';
    const v = value ?? 0;
    switch (effect) {
        case 'heal_pct':
            return `Heals ${Math.round(v)}% of max HP when you drop low`;
        case 'buff_attack_pct':
            return `+${Math.round(v)}% attack for the whole trip`;
        case 'buff_defense_pct':
            return `+${Math.round(v)}% defense for the whole trip`;
        case 'luck_bonus':
            return `+${Math.round(v * 100)}% luck for the whole trip`;
        case 'flee_bonus':
            return `+${Math.round(v)}% escape chance when retreating`;
        case 'cure_injury':
            return 'Cures your injury (use from the Pack)';
        default:
            return trigger ? `${effect} (${trigger})` : effect;
    }
}
