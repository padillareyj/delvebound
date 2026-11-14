import catalogJson from '../../item-art/catalog.json';

type Catalog = {
    families: Record<string, string[]>;
    fallback: Record<string, string>;
    consumables: Record<string, string>;
};

const catalog = catalogJson as Catalog;

const stems = new Set<string>([
    ...Object.values(catalog.families).flat(),
    ...Object.values(catalog.consumables),
]);

/** Empty equipment slots use the catalog's slot fallback. Gear icons come from the server. */
export function glyphIcon(icon?: string | null, slot?: string | null): string {
    if (icon && stems.has(icon)) return icon;
    const family = catalog.fallback[slot ?? ''] ?? catalog.fallback._default;
    return catalog.families[family][0];
}
