import type { ReactNode } from 'react';

export type DialogTone = 'default' | 'danger' | 'success' | 'gold';

export interface DialogOptions {
    title: ReactNode;
    body?: ReactNode;
    icon?: ReactNode;
    tone?: DialogTone;
    confirmLabel?: ReactNode;
    /** Omit the cancel button to make an alert. */
    cancelLabel?: ReactNode | null;
}

export interface OpenDialog extends DialogOptions {
    id: number;
    resolve: (ok: boolean) => void;
}

let current: OpenDialog | null = null;
let nextId = 1;
const listeners = new Set<() => void>();

function emit() {
    listeners.forEach((listener) => listener());
}

export function subscribeDialog(listener: () => void) {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

export function currentDialog() {
    return current;
}

export function closeDialog(ok: boolean) {
    const open = current;
    if (!open) return;
    current = null;
    emit();
    open.resolve(ok);
}

/** A themed replacement for window.confirm. Resolves true on confirm, false on cancel, Escape, or a click outside. */
export function confirmDialog(options: DialogOptions): Promise<boolean> {
    closeDialog(false);
    return new Promise((resolve) => {
        current = { cancelLabel: 'Cancel', confirmLabel: 'Confirm', tone: 'default', ...options, id: nextId++, resolve };
        emit();
    });
}

/** A themed replacement for window.alert. */
export function alertDialog(options: Omit<DialogOptions, 'cancelLabel'>): Promise<void> {
    return confirmDialog({ confirmLabel: 'OK', ...options, cancelLabel: null }).then(() => undefined);
}
