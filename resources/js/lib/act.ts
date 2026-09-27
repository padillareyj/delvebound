import { router } from '@inertiajs/react';
import type { RequestPayload } from '@inertiajs/core';

type Method = 'post' | 'delete';

/**
 * Sends a game write without a page reload. The server answers with a redirect back, and Inertia swaps
 * in the fresh props, so the hero bar, gold, and pack all update in place.
 */
export function act(url: string, data: RequestPayload = {}, opts: { method?: Method; onFinish?: () => void; only?: string[] } = {}) {
    const method = opts.method ?? 'post';
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        preserveState: true,
        onFinish: opts.onFinish,
    });
}
