import clsx from 'clsx';
import { CircleHelp } from 'lucide-react';
import { useEffect, useRef, useSyncExternalStore } from 'react';
import { closeDialog, currentDialog, subscribeDialog, type DialogTone } from '@/lib/dialog';

const TONE: Record<DialogTone, { frame: string; badge: string; confirm: string }> = {
    default: {
        frame: 'border-lantern-500/40',
        badge: 'border-lantern-400/50 bg-lantern-400/10 text-lantern-300',
        confirm: 'btn-primary',
    },
    gold: {
        frame: 'border-lantern-400/60 shadow-[0_0_40px_-10px_var(--color-lantern-400)]',
        badge: 'border-lantern-300/60 bg-lantern-400/15 text-lantern-200',
        confirm: 'btn-primary',
    },
    danger: {
        frame: 'border-blood-500/50',
        badge: 'border-blood-500/50 bg-blood-500/10 text-blood-400',
        confirm: 'btn-danger',
    },
    success: {
        frame: 'border-moss-500/50',
        badge: 'border-moss-500/50 bg-moss-500/10 text-moss-400',
        confirm: 'btn-primary',
    },
};

/** Renders the dialog opened through confirmDialog/alertDialog. Mounted once in the layout. */
export default function GameDialog() {
    const dialog = useSyncExternalStore(subscribeDialog, currentDialog, currentDialog);
    const confirmRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!dialog) return;
        confirmRef.current?.focus();
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') closeDialog(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [dialog]);

    if (!dialog) return null;
    const tone = TONE[dialog.tone ?? 'default'];

    return (
        <div
            className="fixed inset-0 z-[60] grid animate-fade-in place-items-center bg-ink-950/75 p-4 backdrop-blur-sm"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) closeDialog(false);
            }}
        >
            <div
                key={dialog.id}
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="game-dialog-title"
                className={clsx('panel w-full max-w-sm animate-dialog-pop border-2 px-6 pt-7 pb-5 text-center', tone.frame)}
            >
                <div className={clsx('mx-auto mb-4 grid size-16 place-items-center rounded-full border-2 text-3xl', tone.badge)}>
                    {dialog.icon ?? <CircleHelp className="size-8" />}
                </div>
                <h2 id="game-dialog-title" className="font-display text-xl font-bold text-lantern-200">
                    {dialog.title}
                </h2>
                {dialog.body && <div className="mt-2 text-sm text-ink-300">{dialog.body}</div>}
                <div className="mt-6 flex gap-2">
                    {dialog.cancelLabel !== null && (
                        <button type="button" className="btn-ghost flex-1" onClick={() => closeDialog(false)}>
                            {dialog.cancelLabel}
                        </button>
                    )}
                    <button ref={confirmRef} type="button" className={clsx(tone.confirm, 'flex-1')} onClick={() => closeDialog(true)}>
                        {dialog.confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
