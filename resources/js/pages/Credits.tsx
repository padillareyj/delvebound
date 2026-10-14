import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import type { SharedProps } from '@/types';

function CreditsLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-full flex-col">
            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-10">{children}</main>
        </div>
    );
}

export default function Credits() {
    const loggedIn = usePage<SharedProps>().props.auth.account !== null;

    return (
        <>
            <Head title="Credits" />
            <Link href={loggedIn ? '/' : '/login'} className="btn-ghost mb-6">
                <ArrowLeft className="size-4" /> {loggedIn ? 'Back to camp' : 'Sign in'}
            </Link>
            <h1 className="text-3xl font-extrabold tracking-wide text-lantern-200">Credits</h1>
            <p className="mt-3 text-sm leading-relaxed text-ink-200">
                Weapon, armor, shield, jewelry, and potion pictures are painted icons stored in this game and served from this site.
                None of them are taken from World of Warcraft or any other commercial game.
            </p>
            <h2 className="mt-8 text-lg font-bold text-ink-100">CC0 (public domain)</h2>
            <ul className="mt-2 space-y-2 text-sm text-ink-200">
                <li>
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://opengameart.org/content/flare-weapon-icons-2" rel="noreferrer">Flare weapon icons 2</a>
                    {' '}— models by Clint Bellanger, paint by Justin Nichol.
                </li>
                <li>
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://opengameart.org/content/armor-icons-by-equipment-slot" rel="noreferrer">Flare armor icons by equipment slot</a>
                    {' '}— Clint Bellanger and contributors.
                </li>
                <li>
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://opengameart.org/content/osare-weapon-icons" rel="noreferrer">OSARE weapon icons</a>
                    {' '}— Blarumyrran.
                </li>
                <li>
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://opengameart.org/content/2d-rpg-item-icons" rel="noreferrer">2D RPG item icons</a>
                    {' '}— Kit.
                </li>
            </ul>
            <h2 className="mt-8 text-lg font-bold text-ink-100">CC BY 3.0</h2>
            <ul className="mt-2 space-y-2 text-sm text-ink-200">
                <li>
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://opengameart.org/content/fantasy-icon-pack-by-ravenmore-0" rel="noreferrer">Fantasy Icon Pack 2.0</a>
                    {' '}— Ravenmore. Creative Commons Attribution 3.0.
                </li>
                <li>
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://opengameart.org/content/weapon-icons-32x32px-painterly" rel="noreferrer">Weapon icons 32×32 (painterly)</a>
                    {' '}and the{' '}
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://opengameart.org/content/armor-icons-32x32-px-painterly" rel="noreferrer">matching armor, rings, and necklaces</a>
                    {' '}— Scrittl. Used under Creative Commons Attribution 3.0.
                </li>
                <li>
                    Two supply drawings that those packs do not include (a smoke cloud and a bandage) remain SVG icons from{' '}
                    <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://game-icons.net/" rel="noreferrer">game-icons.net</a>
                    {' '}by Lorc, also CC BY 3.0.
                </li>
            </ul>
            <p className="mt-6 text-sm text-ink-300">
                A file list is in{' '}
                <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="/item-art/CREDITS.md">the icon credits file</a>.
                The filename prefix is the pack.
            </p>
        </>
    );
}

Credits.layout = (page: ReactNode) => <CreditsLayout>{page}</CreditsLayout>;
