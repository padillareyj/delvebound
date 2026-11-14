import clsx from 'clsx';
import { glyphIcon } from '@/lib/itemArt';

/**
 * A local item drawing. Painted icons keep their colors and pick up a rarity
 * glow from currentColor. The few SVG fallbacks are masked silhouettes.
 * The filename is chosen on the server.
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
    const src = `/item-art/${id}`;
    if (!id.endsWith('.svg')) {
        return <img src={src} alt="" className={clsx('item-art-raster', className)} />;
    }
    const url = `url("${src}")`;
    return (
        <span
            aria-hidden
            className={clsx('item-art', className)}
            style={{ maskImage: url, WebkitMaskImage: url }}
        />
    );
}
