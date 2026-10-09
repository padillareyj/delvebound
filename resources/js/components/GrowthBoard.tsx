import clsx from 'clsx';
import { Gold, Panel } from '@/components/ui';
import { act } from '@/lib/act';

export interface SkillView {
    code: string;
    name: string;
    glyph: string;
    blurb: string;
    rank: number;
    max_rank: number;
    lines: string[];
    next: { rank: number; gold: number; qty: number; have: number; material: string; ready: boolean } | null;
}

export interface MaterialView {
    code: string;
    name: string;
    kind: string;
    glyph: string;
    description: string;
    qty: number;
    places: string[];
}

export interface HorcruxView {
    name: string;
    tier: number;
    max_tier: number;
    progress: number;
    next_at: number | null;
    per_tier: { hp?: number; attack?: number; defense?: number };
}

export function SkillsBoard({ skills, rowVersion }: { skills: SkillView[]; rowVersion: number }) {
    return (
        <Panel title="Skills">
            <ul className="space-y-3">
                {skills.map((skill) => (
                    <li key={skill.code} className="rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="font-semibold text-ink-100">{skill.glyph} {skill.name}</div>
                                <div className="text-[11px] text-ink-400">{skill.blurb}</div>
                                <div className="mt-1 flex gap-1">
                                    {Array.from({ length: skill.max_rank }, (_, i) => (
                                        <span key={i} className={clsx('size-2 rounded-full', i < skill.rank ? 'bg-lantern-400' : 'bg-ink-700')} />
                                    ))}
                                </div>
                                <div className="mt-1 text-[11px] text-moss-400">{skill.lines.join(' · ')}</div>
                            </div>
                            {skill.next ? (
                                <button
                                    className="btn-primary shrink-0 px-3 py-1.5 text-xs"
                                    disabled={!skill.next.ready}
                                    onClick={() => act(`/character/skills/${skill.code}`, { row_version: rowVersion })}
                                >
                                    Rank {skill.next.rank}
                                </button>
                            ) : (
                                <span className="chip border-lantern-400/40 text-lantern-200">Max</span>
                            )}
                        </div>
                        {skill.next && (
                            <p className="mt-1 text-[11px] text-ink-500">
                                <Gold amount={skill.next.gold} /> · {skill.next.qty} {skill.next.material} ({skill.next.have} owned)
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

export function HorcruxBoard({ horcrux }: { horcrux: HorcruxView }) {
    const per = [
        horcrux.per_tier.hp ? `+${horcrux.per_tier.hp} HP` : null,
        horcrux.per_tier.attack ? `+${horcrux.per_tier.attack} Attack` : null,
        horcrux.per_tier.defense ? `+${horcrux.per_tier.defense} Defense` : null,
    ].filter(Boolean).join(', ');

    return (
        <Panel title={horcrux.name} action={<span className="text-xs text-ink-400">Tier {horcrux.tier}/{horcrux.max_tier}</span>}>
            <p className="text-xs text-ink-400">
                Each tier grants {per}. Feed it from Inventory: click a spare item and choose Feed horcrux.
            </p>
            <div className="mt-3">
                <div className="mb-1 flex justify-between text-[11px] text-ink-400">
                    <span>{horcrux.progress} fed</span>
                    <span>{horcrux.next_at === null ? 'Full' : `${horcrux.next_at} to next tier`}</span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-ink-800">
                    <div
                        className="h-full bg-arcane-400"
                        style={{ width: `${horcrux.next_at ? Math.min(100, (horcrux.progress / horcrux.next_at) * 100) : 100}%` }}
                    />
                </div>
            </div>
        </Panel>
    );
}

export function MaterialsBoard({ materials }: { materials: MaterialView[] }) {
    const owned = materials.filter((m) => m.qty > 0);

    return (
        <Panel title="Loot" action={<span className="text-xs text-ink-400">{owned.length} stacks</span>}>
            <div className="grid grid-cols-4 gap-1 sm:grid-cols-8">
                {materials.map((mat) => (
                    <div key={mat.code} className="group relative grid aspect-square place-items-center rounded-md border border-ink-700 bg-ink-950/80 text-lg">
                        <span className={clsx(mat.qty === 0 && 'opacity-25')}>{mat.glyph}</span>
                        {mat.qty > 0 && <span className="absolute right-0.5 bottom-0.5 text-[10px] font-bold tabular-nums text-ink-100">{mat.qty}</span>}
                        <div className="pointer-events-none absolute bottom-[calc(100%+6px)] left-1/2 z-30 hidden w-52 -translate-x-1/2 rounded-lg border border-ink-600 bg-ink-950 p-2 text-left shadow-xl group-hover:block">
                            <div className="text-sm font-semibold text-ink-100">{mat.name}</div>
                            <div className="text-[10px] uppercase tracking-wider text-ink-400">{mat.kind} · {mat.qty} owned</div>
                            <p className="mt-1 text-[11px] text-ink-300">{mat.description}</p>
                            {mat.kind === 'essence' && mat.places.length > 0 && (
                                <p className="mt-1 text-[11px] text-frost-400">Drops in {mat.places.join(', ')}.</p>
                            )}
                            {mat.kind !== 'essence' && <p className="mt-1 text-[11px] text-frost-400">Drops in every expedition.</p>}
                        </div>
                    </div>
                ))}
            </div>
        </Panel>
    );
}
