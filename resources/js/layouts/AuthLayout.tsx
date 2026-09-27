import type { ReactNode } from 'react';
import Toasts from '@/components/Toasts';

export default function AuthLayout({ children, title, subtitle }: { children: ReactNode; title: string; subtitle: string }) {
    return (
        <div className="relative flex min-h-full items-center justify-center overflow-hidden px-4 py-12">
            <div className="pointer-events-none absolute inset-0">
                <div className="absolute top-1/2 left-1/2 size-[40rem] -translate-x-1/2 -translate-y-1/2 animate-flicker rounded-full bg-lantern-500/10 blur-3xl" />
                <svg className="absolute inset-x-0 bottom-0 h-64 w-full text-ink-950" preserveAspectRatio="none" viewBox="0 0 1200 200">
                    <path fill="currentColor" d="M0 200V120l80-50 70 40 90-80 110 90 60-30 120 70 90-100 100 80 80-40 120 60 90-50 90 60 100-40v170z" opacity="0.9" />
                    <path fill="currentColor" d="M0 200v-40l120-30 100 30 140-40 120 40 160-50 120 40 160-30 140 40 140-20v60z" />
                </svg>
            </div>
            <div className="relative w-full max-w-md animate-rise">
                <div className="mb-8 text-center">
                    <img src="/favicon.svg" alt="" className="mx-auto mb-4 size-16 animate-flicker drop-shadow-[0_0_24px_rgb(245_180_83/0.5)]" />
                    <h1 className="text-4xl font-extrabold tracking-wider text-lantern-200 text-shadow-glow">{title}</h1>
                    <p className="mt-2 text-sm text-ink-300">{subtitle}</p>
                </div>
                <div className="panel p-6 sm:p-8">{children}</div>
            </div>
            <Toasts />
        </div>
    );
}
