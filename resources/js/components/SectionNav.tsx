import { Link, usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { isNavActive, type NavEntry } from '@/lib/nav';

export default function SectionNav({ entry }: { entry: NavEntry }) {
    const { url } = usePage();
    if (!entry.children?.length) return null;

    return (
        <nav aria-label={`${entry.label} sections`} className="mb-5 flex gap-1 overflow-x-auto rounded-xl border border-ink-700/80 bg-ink-900/50 p-1">
            {entry.children.map((child) => {
                const active = isNavActive(url, child);
                const Icon = child.icon;
                return (
                    <Link
                        key={child.href}
                        href={child.href}
                        aria-current={active ? 'page' : undefined}
                        className={clsx(
                            'flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold whitespace-nowrap sm:flex-none sm:px-4',
                            active ? 'bg-lantern-400 text-ink-950' : 'text-ink-300 hover:bg-ink-800 hover:text-ink-100',
                        )}
                    >
                        <Icon className="size-4" />
                        {child.label}
                    </Link>
                );
            })}
        </nav>
    );
}
