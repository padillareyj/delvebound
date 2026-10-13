import { Head, Link } from '@inertiajs/react';
import clsx from 'clsx';
import { ArrowLeft, ChevronLeft, ChevronRight, Swords, Trash2 } from 'lucide-react';
import { ItemGlyph } from '@/components/ItemGlyph';
import { RarityName } from '@/components/ItemCard';
import { OutcomeChip, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { confirmDialog } from '@/lib/dialog';
import { RARITY_BORDER, RARITY_TEXT, formatDateTime, useTimezone } from '@/lib/format';
import type { MailRow, Rarity } from '@/types';

interface Letter extends MailRow {
    body: string;
    payload: Record<string, unknown> | null;
    run_id: number | null;
    newer_id: number | null;
    older_id: number | null;
}

const RARITIES = new Set<Rarity>(['common', 'uncommon', 'rare', 'epic', 'legendary']);

interface LootBrief {
    name: string;
    rarity: Rarity;
    slot: string | null;
    icon: string | null;
}

function lootBriefs(payload: Record<string, unknown> | null, key: string): LootBrief[] {
    const raw = payload?.[key];
    if (!Array.isArray(raw)) return [];
    return raw.flatMap((row) => {
        if (!row || typeof row !== 'object') return [];
        const item = row as Record<string, unknown>;
        const rarity = typeof item.rarity === 'string' && RARITIES.has(item.rarity as Rarity) ? (item.rarity as Rarity) : 'common';
        return [{
            name: typeof item.name === 'string' && item.name !== '' ? item.name : 'Item',
            rarity,
            slot: typeof item.slot === 'string' ? item.slot : null,
            icon: typeof item.icon === 'string' ? item.icon : null,
        }];
    });
}

function LootStrip({ title, items, faded }: { title: string; items: LootBrief[]; faded?: boolean }) {
    if (items.length === 0) return null;
    return (
        <div className={clsx(faded && 'opacity-70')}>
            <div className="label">{title}</div>
            <ul className="mt-2 grid gap-2 sm:grid-cols-2">
                {items.map((item, index) => (
                    <li key={`${item.name}-${index}`} className={clsx('flex items-center gap-2 rounded-lg border bg-ink-950/70 px-2 py-1.5', RARITY_BORDER[item.rarity], RARITY_TEXT[item.rarity])}>
                        <ItemGlyph icon={item.icon} slot={item.slot} className="size-7 shrink-0" />
                        <RarityName name={item.name} rarity={item.rarity} className="truncate text-sm" />
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function MailShow({ letter }: { letter: Letter }) {
    const tz = useTimezone();
    const found = lootBriefs(letter.payload, 'items');
    const lost = lootBriefs(letter.payload, 'lost_items');
    return (
        <>
            <Head title={letter.subject} />
            <div className="mb-4 flex items-center justify-between gap-3">
                <Link href="/mail" className="btn-ghost"><ArrowLeft className="size-4" /> Inbox</Link>
                <div className="flex gap-2">
                    {letter.newer_id && <Link href={`/mail/${letter.newer_id}`} className="btn-ghost" title="Newer"><ChevronLeft className="size-4" /></Link>}
                    {letter.older_id && <Link href={`/mail/${letter.older_id}`} className="btn-ghost" title="Older"><ChevronRight className="size-4" /></Link>}
                </div>
            </div>

            <div className="mx-auto max-w-3xl">
                <Panel bodyClass="p-0">
                    <div className="border-b border-ink-700/70 bg-gradient-to-r from-lantern-500/10 to-transparent px-6 py-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <OutcomeChip outcome={letter.outcome} />
                            {letter.run_id && <span className="text-xs text-ink-500">Run #{letter.run_id}</span>}
                        </div>
                        <h1 className="mt-2 text-2xl font-bold text-lantern-200">{letter.subject}</h1>
                        <p className="mt-1 text-xs text-ink-400">{formatDateTime(letter.created_at, tz)}</p>
                    </div>
                    {(found.length > 0 || lost.length > 0) && (
                        <div className="space-y-4 border-b border-ink-700/70 px-6 py-5">
                            <LootStrip title="Carried home" items={found} />
                            <LootStrip title="Left behind" items={lost} faded />
                        </div>
                    )}
                    <div className="scroll-thin overflow-x-auto px-6 py-5">
                        <pre className="font-mono text-[13px] leading-relaxed whitespace-pre text-ink-200">{letter.body}</pre>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-ink-700/70 px-6 py-4">
                        {letter.trip_id ? (
                            <Link href={`/trips/${letter.trip_id}`} className="btn-primary">
                                <Swords className="size-4" /> Watch the battle replay
                            </Link>
                        ) : (
                            <span />
                        )}
                        <button
                            className="btn-danger"
                            onClick={async () => {
                                const ok = await confirmDialog({
                                    tone: 'danger',
                                    icon: <Trash2 className="size-8" />,
                                    title: 'Delete this letter?',
                                    body: 'It is gone for good.',
                                    confirmLabel: 'Delete',
                                });
                                if (ok) act(`/mail/${letter.id}`, {}, { method: 'delete' });
                            }}
                        >
                            <Trash2 className="size-4" /> Delete
                        </button>
                    </div>
                </Panel>
            </div>
        </>
    );
}
