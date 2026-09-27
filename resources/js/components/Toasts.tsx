import { usePage } from '@inertiajs/react';
import clsx from 'clsx';
import { CheckCircle2, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { SharedProps } from '@/types';

interface Toast {
    id: number;
    kind: 'success' | 'error';
    text: string;
}

let nextId = 1;

export default function Toasts() {
    const { flash, errors } = usePage<SharedProps>().props;
    const [toasts, setToasts] = useState<Toast[]>([]);

    useEffect(() => {
        const incoming: Toast[] = [];
        if (flash?.success) incoming.push({ id: nextId++, kind: 'success', text: flash.success });
        if (flash?.error) incoming.push({ id: nextId++, kind: 'error', text: flash.error });
        const fieldErrors = Object.values(errors ?? {});
        if (fieldErrors.length && !flash?.error) incoming.push({ id: nextId++, kind: 'error', text: fieldErrors[0] });
        if (!incoming.length) return;
        setToasts((t) => [...t, ...incoming].slice(-4));
        const timers = incoming.map((toast) =>
            window.setTimeout(() => setToasts((t) => t.filter((x) => x.id !== toast.id)), toast.kind === 'error' ? 7000 : 4000),
        );
        return () => timers.forEach(clearTimeout);
    }, [flash, errors]);

    return (
        <div className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2">
            {toasts.map((t) => (
                <div
                    key={t.id}
                    role="status"
                    className={clsx(
                        'pointer-events-auto flex animate-rise items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-2xl backdrop-blur-md',
                        t.kind === 'success'
                            ? 'border-moss-500/40 bg-ink-850/95 text-ink-100'
                            : 'border-blood-500/50 bg-ink-850/95 text-ink-100',
                    )}
                >
                    {t.kind === 'success' ? (
                        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-moss-400" />
                    ) : (
                        <TriangleAlert className="mt-0.5 size-4 shrink-0 text-blood-400" />
                    )}
                    <span className="flex-1">{t.text}</span>
                    <button className="text-ink-400 hover:text-ink-100" onClick={() => setToasts((x) => x.filter((y) => y.id !== t.id))}>
                        <X className="size-4" />
                    </button>
                </div>
            ))}
        </div>
    );
}
