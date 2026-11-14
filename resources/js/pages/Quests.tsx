import { Head, Link } from '@inertiajs/react';
import clsx from 'clsx';
import { Check, Gift, ScrollText, Sparkles, Star } from 'lucide-react';
import { useState } from 'react';
import { PageHeader, Panel } from '@/components/ui';
import { act } from '@/lib/act';
import { ADVANCED_ELEMENT_LABELS, ELEMENT_META, ELEMENTS, bonusLine } from '@/lib/format';

interface Quest {
    code: string;
    name: string;
    blurb: string;
    type: string;
    target: number;
    progress: number;
    activity: number;
    rewards: Record<string, number>;
    claimed: boolean;
    ready: boolean;
}

interface Chest {
    at: number;
    rewards: Record<string, number>;
    claimed: boolean;
    ready: boolean;
}

interface JewelRow {
    element: string;
    level: number;
    max: number;
    cost: number | null;
    can: boolean;
    per_level: Record<string, number>;
}

interface Track {
    level: number;
    max: number;
    cost: number | null;
    can: boolean;
    per_level: Record<string, number>;
    material: string;
}

interface NexusTrack extends Track {
    sigil_sum: number;
    need_sum: number | null;
}

interface Props {
    rowVersion: number;
    day: string;
    timezone: string;
    activity: number;
    quests: Quest[];
    chests: Chest[];
    systems: {
        jewels: JewelRow[];
        excellency: Track;
        wing_stars: Track;
        sigils: JewelRow[];
        sigil_nexus: NexusTrack;
        bag: { jewel_dust: number; excellent_stone: number; wing_feather: number; sigil_ink: number };
        bonus: Record<string, number>;
        power: number;
    };
}

const MAT: Record<string, string> = {
    jewel_dust: 'Jewel Dust',
    excellent_stone: 'Excellent Stone',
    wing_feather: 'Wing Feather',
    sigil_ink: 'Sigil Ink',
};

const SIGIL_RING: Record<(typeof ELEMENTS)[number], string> = {
    fire: 'border-orange-400/70 shadow-[0_0_18px_rgba(251,146,60,0.45)]',
    water: 'border-frost-400/70 shadow-[0_0_18px_rgba(125,211,252,0.4)]',
    thunder: 'border-arcane-400/70 shadow-[0_0_18px_rgba(192,132,252,0.45)]',
    earth: 'border-lantern-300/70 shadow-[0_0_18px_rgba(250,204,21,0.35)]',
    wind: 'border-moss-400/70 shadow-[0_0_18px_rgba(74,222,128,0.4)]',
};

function rewardText(rewards: Record<string, number>) {
    return Object.entries(rewards).map(([code, qty]) => `+${qty} ${MAT[code] ?? code}`).join(' · ');
}

export default function Quests(props: Props) {
    const [busy, setBusy] = useState<string | null>(null);
    const send = (url: string, data: Record<string, string | number>, key: string) => {
        setBusy(key);
        act(url, { ...data, row_version: props.rowVersion }, { onFinish: () => setBusy(null) });
    };
    const bag = props.systems.bag;
    const bonus = Object.entries(props.systems.bonus);
    const nexus = props.systems.sigil_nexus;

    return (
        <>
            <Head title="Daily Quests" />
            <PageHeader
                title="Daily Quests"
                subtitle={<>MU-style board for {props.day} ({props.timezone}). Finish trips and the arena, claim dust and ink, then raise Jewels, the Sigil Circle, Excellency, and Wing Stars.</>}
            >
                <span className="chip border-lantern-400/50 bg-lantern-400/10 text-lantern-200">
                    <ScrollText className="size-4" /> Activity {props.activity}
                </span>
                <span className="chip border-ink-600 text-ink-300">💎 {bag.jewel_dust}</span>
                <span className="chip border-ink-600 text-ink-300">🔶 {bag.excellent_stone}</span>
                <span className="chip border-ink-600 text-ink-300">🪶 {bag.wing_feather}</span>
                <span className="chip border-arcane-400/40 bg-arcane-400/10 text-arcane-200">🖌 {bag.sigil_ink}</span>
            </PageHeader>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_22rem]">
                <div className="space-y-6">
                    <Panel title="Today's quests">
                        <ul className="space-y-2">
                            {props.quests.map((quest) => (
                                <li key={quest.code} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2.5">
                                    <div className="min-w-0">
                                        <div className="font-semibold text-ink-100">{quest.name}</div>
                                        <div className="text-xs text-ink-400">{quest.blurb}</div>
                                        <div className="mt-1 text-[11px] tabular-nums text-ink-400">
                                            {quest.progress}/{quest.target} · +{quest.activity} activity · {rewardText(quest.rewards)}
                                        </div>
                                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-ink-800">
                                            <div className="h-full bg-lantern-400" style={{ width: `${Math.min(100, (quest.progress / quest.target) * 100)}%` }} />
                                        </div>
                                    </div>
                                    {quest.claimed ? (
                                        <span className="chip border-moss-500/40 text-moss-400"><Check className="size-3.5" /> Claimed</span>
                                    ) : (
                                        <button className="btn-primary" disabled={!quest.ready || busy !== null} onClick={() => send('/quests/claim', { code: quest.code }, quest.code)}>
                                            Claim
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Panel>

                    <Panel title="Activity chests">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {props.chests.map((chest) => (
                                <div key={chest.at} className={clsx('rounded-xl border p-3', chest.ready || chest.claimed ? 'border-lantern-400/40 bg-lantern-400/5' : 'border-ink-700 bg-ink-900/40')}>
                                    <div className="flex items-center gap-2 font-semibold text-ink-100">
                                        <Gift className="size-4 text-lantern-300" /> {chest.at} activity
                                    </div>
                                    <p className="mt-1 text-xs text-ink-400">{rewardText(chest.rewards)}</p>
                                    {chest.claimed ? (
                                        <span className="mt-3 inline-flex chip border-moss-500/40 text-moss-400"><Check className="size-3.5" /> Opened</span>
                                    ) : (
                                        <button className="btn-primary mt-3 w-full justify-center" disabled={!chest.ready || busy !== null} onClick={() => send('/quests/chest', { at: chest.at }, `chest:${chest.at}`)}>
                                            Open
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <Panel
                        title={<>Sigil Circle</>}
                        action={<span className="text-xs text-ink-400">sum {nexus.sigil_sum} · Nexus Lv {nexus.level}/{nexus.max}</span>}
                    >
                        <p className="mb-4 text-sm text-ink-400">
                            Five elemental sigils orbit a Nexus. Paint each with Sigil Ink, then awaken the center when the ring is strong enough.
                        </p>
                        <div className="sigil-stage mx-auto">
                            <div className="sigil-halo animate-sigil-spin" aria-hidden />
                            <div className="sigil-ring-line" aria-hidden />
                            <button
                                type="button"
                                className={clsx('sigil-nexus', nexus.level > 0 && 'is-lit', nexus.can && 'is-ready')}
                                disabled={!nexus.can || busy !== null}
                                onClick={() => send('/quests/nexus', {}, 'nexus')}
                                title={nexus.cost !== null
                                    ? `Nexus next costs ${nexus.cost} ink · need ${nexus.need_sum} sigil levels`
                                    : 'Nexus maxed'}
                            >
                                <span className="sigil-nexus-core animate-sigil-pulse">✦</span>
                                <span className="sigil-nexus-label">Nexus</span>
                                <span className="sigil-nexus-lv">Lv {nexus.level}/{nexus.max}</span>
                                {nexus.cost !== null && (
                                    <span className="sigil-nexus-cost">
                                        {nexus.need_sum !== null && nexus.sigil_sum < nexus.need_sum
                                            ? `${nexus.sigil_sum}/${nexus.need_sum} sum`
                                            : `${nexus.cost} ink`}
                                    </span>
                                )}
                            </button>
                            {props.systems.sigils.map((sigil, index) => {
                                const meta = ELEMENT_META[sigil.element as (typeof ELEMENTS)[number]];
                                const lit = sigil.level > 0;
                                return (
                                    <button
                                        key={sigil.element}
                                        type="button"
                                        className={clsx(
                                            'sigil-node',
                                            `sigil-node-${index}`,
                                            lit && 'is-lit',
                                            sigil.can && 'is-ready',
                                            lit && SIGIL_RING[sigil.element as (typeof ELEMENTS)[number]],
                                        )}
                                        disabled={!sigil.can || busy !== null}
                                        onClick={() => send('/quests/sigil', { element: sigil.element }, `sigil:${sigil.element}`)}
                                        title={`${meta.label} · next ${sigil.cost ?? 'max'} ink`}
                                    >
                                        <span className="sigil-glyph">{meta.glyph}</span>
                                        <span className={clsx('sigil-name', meta.tone)}>{meta.label}</span>
                                        <span className="sigil-lv">Lv {sigil.level}/{sigil.max}</span>
                                    </button>
                                );
                            })}
                        </div>
                        <ul className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {props.systems.sigils.map((sigil) => (
                                <li key={`tip-${sigil.element}`} className="rounded-lg border border-ink-700/60 bg-ink-950/40 px-2.5 py-1.5 text-[11px] text-ink-400">
                                    <span className="font-semibold text-ink-200">{ELEMENT_META[sigil.element as (typeof ELEMENTS)[number]].label}</span>
                                    {' · '}
                                    {Object.entries(sigil.per_level).slice(0, 2).map(([k, v]) => `${ADVANCED_ELEMENT_LABELS[k.replace(`${sigil.element}_`, '')] ?? k} +${v}`).join(' · ')}
                                    {sigil.cost !== null && <> · next {sigil.cost} ink</>}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-3 rounded-lg border border-arcane-400/30 bg-arcane-400/5 px-3 py-2 text-xs text-ink-300">
                            <div className="font-semibold text-arcane-200">Nexus / level</div>
                            <ul className="mt-1 space-y-0.5 text-moss-400">
                                {Object.entries(nexus.per_level).slice(0, 4).map(([key, value]) => (
                                    <li key={key}>{bonusLine(key, value)}</li>
                                ))}
                            </ul>
                        </div>
                    </Panel>
                </div>

                <div className="space-y-6">
                    <Panel title={<><Sparkles className="mr-1 inline size-4" /> Systems bonus</>} action={<span className="text-xs text-ink-400">+{props.systems.power} power</span>}>
                        {bonus.length === 0 ? (
                            <p className="text-sm text-ink-400">Raise a jewel, sigil, Excellency, or Wing Star to feed the Detail combat sheet.</p>
                        ) : (
                            <ul className="space-y-0.5 text-sm">
                                {bonus.map(([key, value]) => (
                                    <li key={key} className="font-semibold text-moss-400">{bonusLine(key, value)}</li>
                                ))}
                            </ul>
                        )}
                        <Link href="/character?tab=detail" className="btn-ghost mt-3 w-full justify-center">Open Detail sheet</Link>
                    </Panel>

                    <Panel title="Elemental jewels">
                        <ul className="space-y-2">
                            {props.systems.jewels.map((jewel) => {
                                const meta = ELEMENT_META[jewel.element as (typeof ELEMENTS)[number]];
                                return (
                                    <li key={jewel.element} className="rounded-lg border border-ink-700/70 bg-ink-900/40 px-3 py-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <div>
                                                <div className={clsx('font-semibold', meta.tone)}>{meta.glyph} {meta.label} · Lv {jewel.level}/{jewel.max}</div>
                                                <div className="text-[11px] text-ink-400">
                                                    {Object.entries(jewel.per_level).slice(0, 2).map(([k, v]) => `${ADVANCED_ELEMENT_LABELS[k.replace(`${jewel.element}_`, '')] ?? k} +${v}`).join(' · ')}
                                                    {jewel.cost !== null && <> · next {jewel.cost} dust</>}
                                                </div>
                                            </div>
                                            <button className="btn-primary px-3 py-1.5 text-xs" disabled={!jewel.can || busy !== null}
                                                onClick={() => send('/quests/jewel', { element: jewel.element }, `jewel:${jewel.element}`)}>
                                                Upgrade
                                            </button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </Panel>

                    <TrackPanel
                        title="Excellency"
                        glyph="🔶"
                        track={props.systems.excellency}
                        busy={busy !== null}
                        onUpgrade={() => send('/quests/excellency', {}, 'excellency')}
                    />
                    <TrackPanel
                        title="Wing Stars"
                        glyph="🪶"
                        track={props.systems.wing_stars}
                        busy={busy !== null}
                        onUpgrade={() => send('/quests/wing', {}, 'wing')}
                        icon={<Star className="size-4 text-lantern-300" />}
                    />
                </div>
            </div>
        </>
    );
}

function TrackPanel({ title, glyph, track, busy, onUpgrade, icon }: {
    title: string;
    glyph: string;
    track: Track;
    busy: boolean;
    onUpgrade: () => void;
    icon?: React.ReactNode;
}) {
    return (
        <Panel title={<>{icon ?? glyph} {title}</>}>
            <div className="flex items-center justify-between gap-3">
                <div>
                    <div className="font-display text-2xl font-bold text-lantern-200">Lv {track.level}/{track.max}</div>
                    <div className="text-xs text-ink-400">
                        {track.cost !== null ? `Next costs ${track.cost} ${MAT[track.material] ?? track.material}` : 'Maxed'}
                    </div>
                </div>
                <button className="btn-primary" disabled={!track.can || busy} onClick={onUpgrade}>Upgrade</button>
            </div>
            <ul className="mt-3 space-y-0.5 text-xs text-moss-400">
                {Object.entries(track.per_level).map(([key, value]) => (
                    <li key={key}>{bonusLine(key, value)} / level</li>
                ))}
            </ul>
        </Panel>
    );
}
