import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, Swords, Trash2 } from 'lucide-react';
import { OutcomeChip, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { formatDateTime, useTimezone } from '@/lib/format';
import type { MailRow } from '@/types';

interface Letter extends MailRow {
    body: string;
    payload: Record<string, unknown> | null;
    run_id: number | null;
    newer_id: number | null;
    older_id: number | null;
}

export default function MailShow({ letter }: { letter: Letter }) {
    const tz = useTimezone();
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
                            onClick={() => {
                                if (window.confirm('Delete this letter?')) act(`/mail/${letter.id}`, {}, { method: 'delete' });
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
