import type { ReactNode } from 'react';

/**
 * Original gear silhouettes. The shape follows item type (wand, staff, bow,
 * one-hand and two-hand blades) and falls back to the equipment slot.
 */
export function ItemGlyph({
    slot,
    itemType,
    twoHanded = false,
    className,
}: {
    slot: string;
    itemType?: string | null;
    twoHanded?: boolean;
    className?: string;
}) {
    return (
        <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden>
            {shapes(glyphKey(slot, itemType, twoHanded))}
        </svg>
    );
}

function glyphKey(slot: string, itemType: string | null | undefined, twoHanded: boolean): string {
    if (itemType === 'sword') return twoHanded ? 'greatsword' : 'sword';
    if (itemType === 'axe') return twoHanded ? 'greataxe' : 'axe';
    if (itemType === 'dagger' || itemType === 'staff' || itemType === 'wand' || itemType === 'bow' || itemType === 'shield') {
        return itemType;
    }
    if (itemType === 'cloth' || itemType === 'leather' || itemType === 'mail') {
        const place = slot === 'head' || slot === 'chest' || slot === 'hands' || slot === 'feet' ? slot : 'chest';
        return `${itemType}-${place}`;
    }
    if (itemType === 'jewelry' || slot === 'ring' || slot === 'ring1' || slot === 'ring2') return 'ring';
    if (slot === 'amulet') return 'amulet';
    if (slot === 'weapon') return 'sword';
    if (slot === 'offhand') return 'shield';
    if (slot === 'head') return 'mail-head';
    if (slot === 'chest') return 'mail-chest';
    if (slot === 'hands') return 'mail-hands';
    if (slot === 'feet') return 'mail-feet';
    return 'ring';
}

function shapes(key: string): ReactNode {
    switch (key) {
        case 'sword':
            return <path d="M10.1 1.1h3.8l.5 12.6h4.3v2.3h-4.1V19.6l1.15 2.5H8.25L9.4 19.6v-3.6H5.3v-2.3h4.3Z" />;
        case 'greatsword':
            return <path d="M8.4.7h7.2l.7 13.6h4.3v2.5h-3.7V22h-9v-5.2H4.4v-2.5h4.7Z" />;
        case 'dagger':
            return <path d="M12 1.1 14.5 5.4V9.2h2.6v2H14.3v4.2H9.7V11.2H7.1V9.2h2.4V5.4Z" />;
        case 'axe':
            return (
                <>
                    <path d="M9.6 21.8h3.1L14.2 9.2h-3.2Z" />
                    <path d="M12.6 2.6c3.4-.3 6.6 1.5 7.3 4.2.7 2.6-.8 4.8-3.2 5.5-1.7.5-3.2-.1-4.1-1.3.4-2.6.2-5.6 0-8.4Z" />
                </>
            );
        case 'greataxe':
            return (
                <>
                    <path d="M10.6 22.2h2.8L14.6 8.4h-2.9Z" />
                    <path d="M13.2 1.6c4.2-.2 7.6 2.2 8.2 5.4.7 3.4-1.4 6.2-4.4 7-2.2.6-4-.2-5-1.8.3-3.4 0-7.2 1.2-10.6Z" />
                    <path d="M12.2 2.4C8.4 2.8 5.6 5.4 5.2 8.2c-.4 2.6 1.1 4.6 3.2 5.1 1.5.4 2.8-.2 3.5-1.3-.2-3-.1-6.4.3-9.6Z" />
                </>
            );
        case 'wand':
            return (
                <>
                    <path d="m6.2 20.8 8.6-12.2 2.2 1.6L8.4 22.4Z" />
                    <path d="m16.6 1.2 1.35 3.3 3.45.95-3.45.95L16.6 9.7l-1.35-3.3-3.45-.95 3.45-.95Z" />
                </>
            );
        case 'staff':
            return (
                <>
                    <path d="M10.7 22.4h2.6V10.2h-2.6Z" />
                    <path d="M6.4 10.6h11.2v1.8H6.4Z" />
                    <circle cx="12" cy="5.4" r="3.6" />
                </>
            );
        case 'bow':
            return (
                <>
                    <path d="M6.2 2.2c5.6 2.2 8.2 5.2 8.2 9.8s-2.6 7.6-8.2 9.8c1.3-2.5 3.6-4.6 6.2-5.8 2-1 3.4-2.3 3.4-4s-1.4-3-3.4-4C9.8 6.8 7.5 4.7 6.2 2.2Z" />
                    <path d="M5.5 2.6h1.7v18.8H5.5Z" />
                    <path d="M7.4 11.1h9.2l-2.3-1.7v1.15h-6.9Zm6.9 1.8 2.3-1.7v3.4Z" />
                </>
            );
        case 'shield':
            return <path d="M12 1.3 21 5.2v6.8c0 5.4-3.7 8.7-9 10.7-5.3-2-9-5.3-9-10.7V5.2Z" />;
        case 'cloth-head':
            return <path d="M12 2C6.8 2.8 4 7 4 11.8c2.1.5 4.1 0 5.6-1.4L12 13.2l2.4-2.8c1.5 1.4 3.5 1.9 5.6 1.4C20 7 17.2 2.8 12 2ZM7.4 13.6c1 3.2 2.5 5.2 4.6 6.2 2.1-1 3.6-3 4.6-6.2-1.3.7-2.9 1-4.6 1s-3.3-.3-4.6-1Z" />;
        case 'leather-head':
            return <path d="M5.6 12.2a6.4 6.2 0 0 1 12.8 0V14H5.6Zm-1.8 2.6h16.4V17l-2.4 1.5H6.2L3.8 17Z" />;
        case 'mail-head':
            return <path d="M12 1.8c-4 0-6.8 2.8-6.8 7v4.2h2.4V9.6h8.8v3.4h2.4V8.8c0-4.2-2.8-7-6.8-7ZM3.4 14.2h17.2V17H3.4Zm2.6 2.6h12v2.4H6Z" />;
        case 'cloth-chest':
            return <path d="M9.2 2.6h5.6L16.6 6h3.2v4.4L12 21.6 4.2 10.4V6h3.2Z" />;
        case 'leather-chest':
            return <path d="M8.2 3.2 5 6.4V20h14V6.4l-3.2-3.2H8.2Zm.6 8.2h6.4v1.8H8.8Z" />;
        case 'mail-chest':
            return <path d="M8 2.8 6.2 6.2H3.8l1.2 3.4L3.6 12.4l3.2 1.4.8 6.6h8.8l.8-6.6 3.2-1.4-1.4-2.8 1.2-3.4h-2.4L16 2.8 12 5.2Zm.8 8.6h6.4v1.6H8.8Zm0 2.8h6.4v1.6H8.8Z" />;
        case 'cloth-hands':
        case 'leather-hands':
        case 'mail-hands':
            return <path d="M9.2 8.4C6.2 8.8 4.6 11.6 5.1 15.2c.4 3 2.6 5 5.4 5h3.6c3 0 5.2-2.4 5.2-5.4V10.2c0-1.8-1.6-2.8-3.2-2.2V5.2h-2.8v2.6H9.2Z" />;
        case 'cloth-feet':
            return <path d="M8.4 3.6h3.6v7.4l5.4 1.8c1.5.5 2.4 1.9 2.4 3.5v1.5H5.6v-3l2.8-1.2Z" />;
        case 'leather-feet':
        case 'mail-feet':
            return <path d="M8.6 2.6h4v7l5.6 2.2c1.4.6 2.2 1.9 2.2 3.4V20H5v-4.4l3.6-1.6Z" />;
        case 'amulet':
            return (
                <>
                    <path d="M6.4 4.4c1.8 2.8 3.4 3.8 5.6 3.8s3.8-1 5.6-3.8" fill="none" stroke="currentColor" strokeWidth="1.8" />
                    <path d="m12 8.6 5.2 4.4L12 21.2 6.8 13Z" />
                </>
            );
        case 'ring':
        default:
            return (
                <>
                    <circle cx="12" cy="14.2" r="5.4" fill="none" stroke="currentColor" strokeWidth="2.5" />
                    <path d="m12 2.2 1.6 3.4L12 8.4 10.4 5.6Z" />
                </>
            );
    }
}
