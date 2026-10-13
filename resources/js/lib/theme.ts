import { useLayoutEffect } from 'react';

/** Sunny village. Every page outside a map uses this palette. */
export const DEFAULT_THEME = 'meadow';

/** Browser chrome color. Matches each palette's page background. */
export const THEME_COLOR: Record<string, string> = {
    meadow: '#f7f1e2',
    wilds: '#e5f2da',
    salt: '#e5f2ec',
    dune: '#fbf3e2',
    ash: '#16110e',
    gale: '#101722',
    gilt: '#1a140c',
    mycel: '#14111a',
    void: '#0c0c16',
    coil: '#0e160f',
    brass: '#181410',
    crimson: '#1a0e12',
};

/** Authored region code → palette. Generated regions use the theme suffix instead. */
const AUTHORED: Record<string, string> = {
    wilds: 'wilds',
    salt_marches: 'salt',
    ashen_crown: 'ash',
};

/** App\Game\World\ThemeCatalog codes. A generated region is gen{n}_{code}. */
const GENERATED = new Set(['gale', 'gilt', 'mycel', 'void', 'coil', 'brass', 'dune', 'crimson']);

/** Unknown maps cycle the three authored palettes, same order as the old parchments. */
const FALLBACK = ['wilds', 'salt', 'ash'] as const;

export function themeForRegion(code: string | null | undefined, index = 0): string {
    if (code && AUTHORED[code]) return AUTHORED[code];
    if (code) {
        const suffix = code.slice(code.lastIndexOf('_') + 1);
        if (GENERATED.has(suffix)) return suffix;
    }
    const i = ((index % FALLBACK.length) + FALLBACK.length) % FALLBACK.length;
    return FALLBACK[i];
}

/** Painted canvas class for a palette. The Wilds use the base parchment. */
export function parchmentClass(theme: string): string {
    return theme === 'wilds' ? 'parchment' : `parchment parchment-${theme}`;
}

/** Paint the document for this palette, and put the previous one back on cleanup. */
export function useTheme(theme: string): void {
    useLayoutEffect(() => {
        const root = document.documentElement;
        const previous = root.dataset.theme ?? DEFAULT_THEME;
        root.dataset.theme = theme;
        const meta = document.querySelector('meta[name="theme-color"]');
        const previousColor = meta?.getAttribute('content') ?? null;
        meta?.setAttribute('content', THEME_COLOR[theme] ?? THEME_COLOR[DEFAULT_THEME]);
        return () => {
            root.dataset.theme = previous;
            if (meta && previousColor) meta.setAttribute('content', previousColor);
        };
    }, [theme]);
}
