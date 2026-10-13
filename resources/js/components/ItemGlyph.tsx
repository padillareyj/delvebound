import clsx from 'clsx';
import { glyphIcon } from '@/lib/itemArt';

/**
 * A local game-icons.net drawing, tinted with currentColor (the rarity variables
 * when a parent uses text-rarity-*). The icon stem is chosen on the server.
 */
export function ItemGlyph({
    icon,
    slot,
    className,
}: {
    icon?: string | null;
    slot?: string | null;
    className?: string;
}) {
    const id = glyphIcon(icon, slot);
    const url = `url("/icons/items/${id}.svg")`;
    return (
        <span
            aria-hidden
            className={clsx('item-art', className)}
            style={{ maskImage: url, WebkitMaskImage: url }}
        />
    );
}
