import {
    Bot,
    Castle,
    Crown,
    Gem,
    Mail,
    Map as MapIcon,
    Store,
    Swords,
    UserRound,
    type LucideIcon,
} from 'lucide-react';

export interface NavLeaf {
    href: string;
    label: string;
    hint?: string;
    icon: LucideIcon;
    match: (path: string) => boolean;
}

export interface NavEntry extends NavLeaf {
    children?: NavLeaf[];
}

export const MARKET_NAV: NavEntry = {
    href: '/market',
    label: 'Market',
    icon: Store,
    match: (path) => path.startsWith('/market') || path.startsWith('/premium'),
    children: [
        { href: '/market', label: 'Market', hint: 'Potions, tonics, and steel', icon: Store, match: (path) => path.startsWith('/market') },
        { href: '/premium', label: 'Premium', hint: 'Titles, wings, and looks', icon: Gem, match: (path) => path.startsWith('/premium') },
    ],
};

export const RANKS_NAV: NavEntry = {
    href: '/leaderboard',
    label: 'Ranks',
    icon: Crown,
    match: (path) => path.startsWith('/leaderboard') || path.startsWith('/bots') || path.startsWith('/arena'),
    children: [
        { href: '/leaderboard', label: 'Ranks', hint: 'Hall of Lanterns', icon: Crown, match: (path) => path.startsWith('/leaderboard') },
        { href: '/bots', label: 'Rivals', hint: 'The six adventurers', icon: Bot, match: (path) => path.startsWith('/bots') },
        { href: '/arena', label: 'Arena', hint: 'Knockout bouts', icon: Swords, match: (path) => path.startsWith('/arena') },
    ],
};

export const NAV: NavEntry[] = [
    { href: '/', label: 'Camp', icon: Castle, match: (path) => path === '/' },
    { href: '/character', label: 'Hero', icon: UserRound, match: (path) => path.startsWith('/character') },
    { href: '/map', label: 'Map', icon: MapIcon, match: (path) => path.startsWith('/map') },
    MARKET_NAV,
    RANKS_NAV,
    { href: '/mail', label: 'Mail', icon: Mail, match: (path) => path.startsWith('/mail') || path.startsWith('/trips') },
];

export function isNavActive(url: string, item: NavLeaf): boolean {
    return item.match(url.split('?')[0]);
}
