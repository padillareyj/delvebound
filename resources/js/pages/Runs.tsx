import { Head, Link } from '@inertiajs/react';
import clsx from 'clsx';
import { Terminal } from 'lucide-react';
import { Empty, PageHeader, Panel } from '@/components/ui';
import { formatDateTime, useTimezone } from '@/lib/format';

interface Run {
    id: number;
    status: string;
    seed: string;
    started_at: string | null;
    finished_at: string | null;
    host: string | null;
    characters_done: number;
    characters_total: number;
    resume_count: number;
    args: Record<string, unknown> | string[];
    error: string | null;
    outcomes: Record<string, number>;
}

const STATUS_STYLE: Record<string, string> = {
    completed: 'border-moss-500/50 bg-moss-500/10 text-moss-400',
    running: 'border-lantern-400/50 bg-lantern-400/10 text-lantern-300',
    failed: 'border-blood-500/50 bg-blood-500/10 text-blood-400',
    abandoned: 'border-ink-500 bg-ink-700/40 text-ink-300',
};

function duration(a: string | null, b: string | null): string {
    if (!a || !b) return '—';
    const s = Math.round((new Date(b).getTime() - new Date(a).getTime()) / 1000);
    return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`;
}

export default function Runs({ runs }: { runs: Run[] }) {
    const tz = useTimezone();
    return (
        <>
            <Head title="Runs" />
            <PageHeader title="Simulation runs" subtitle="Read-only history. Runs are started by hand on the server; the website never starts one.">
                <Link href="/admin" className="btn-ghost">Gear templates</Link>
                <code className="flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-950 px-3 py-2 text-xs text-lantern-200">
                    <Terminal className="size-3.5" /> php artisan delve:run
                </code>
            </PageHeader>
            <Panel bodyClass="p-0">
                {runs.length === 0 ? (
                    <Empty icon="📜" title="No runs yet" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-[11px] uppercase tracking-wider text-ink-400">
                                <tr className="border-b border-ink-700/60">
                                    <th className="px-4 py-3">Run</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Started</th>
                                    <th className="px-4 py-3">Duration</th>
                                    <th className="px-4 py-3">Progress</th>
                                    <th className="px-4 py-3">Outcomes</th>
                                    <th className="px-4 py-3">Seed</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink-800">
                                {runs.map((r) => (
                                    <tr key={r.id} className="align-top">
                                        <td className="px-4 py-3 font-display font-bold text-lantern-200">#{r.id}</td>
                                        <td className="px-4 py-3">
                                            <span className={clsx('chip', STATUS_STYLE[r.status] ?? STATUS_STYLE.abandoned)}>{r.status}</span>
                                            {r.resume_count > 0 && <div className="mt-1 text-[11px] text-ink-500">resumed ×{r.resume_count}</div>}
                                            {r.error && <div className="mt-1 max-w-xs truncate text-[11px] text-blood-400" title={r.error}>{r.error}</div>}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-ink-300">{formatDateTime(r.started_at, tz)}</td>
                                        <td className="px-4 py-3 text-ink-300 tabular-nums">{duration(r.started_at, r.finished_at)}</td>
                                        <td className="px-4 py-3 tabular-nums">{r.characters_done}/{r.characters_total}</td>
                                        <td className="px-4 py-3 text-xs">
                                            {Object.entries(r.outcomes).map(([k, v]) => (
                                                <span key={k} className="mr-2 whitespace-nowrap text-ink-300">{v} {k}</span>
                                            ))}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs text-ink-400">{r.seed}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </>
    );
}
