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
                Weapon, armor, shield, jewelry, and potion drawings are SVG icons from{' '}
                <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://game-icons.net/" rel="noreferrer">game-icons.net</a>,
                used under the{' '}
                <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://creativecommons.org/licenses/by/3.0/" rel="noreferrer">Creative Commons Attribution 3.0 Unported</a>{' '}
                license. The files are stored with this game and served from this site.
            </p>
            <h2 className="mt-8 text-lg font-bold text-ink-100">Authors</h2>
            <ul className="mt-2 space-y-1 text-sm text-ink-200">
                <li>Lorc — <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://lorcblog.blogspot.com" rel="noreferrer">lorcblog.blogspot.com</a></li>
                <li>Delapouite — <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="https://delapouite.com" rel="noreferrer">delapouite.com</a></li>
                <li>Skoll</li>
                <li>Carl Olsen</li>
            </ul>
            <p className="mt-6 text-sm text-ink-300">
                The drawings themselves are unchanged. A full file list is in{' '}
                <a className="text-lantern-200 underline decoration-ink-700 underline-offset-2" href="/icons/items/CREDITS.md">the icon credits file</a>.
            </p>
        </>
    );
}

Credits.layout = (page: ReactNode) => <CreditsLayout>{page}</CreditsLayout>;
