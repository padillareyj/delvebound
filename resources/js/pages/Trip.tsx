import { Head, Link } from '@inertiajs/react';
import clsx from 'clsx';
import { ChevronLeft, ChevronsRight, Crown, FastForward, Mail, Pause, Play, RotateCcw, SkipForward, Skull, StepForward } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ItemGlyph } from '@/components/ItemGlyph';
import { RarityName } from '@/components/ItemCard';
import { Bar, Gold, OutcomeChip, Panel } from '@/components/ui';
import { OUTCOME_META, PLACE_ICONS, RARITY_TEXT, formatDateTime, useTimezone } from '@/lib/format';
import type { Encounter, LogEvent, Trip, Unit } from '@/types';

const HERO = '__hero__';
const SPEEDS = [1, 2, 4] as const;
const STEP_MS = 850;

type Frame = { hero: number; monsters: Record<string, number> };
type Popup = { id: number; unit: string; text: string; kind: 'dmg' | 'crit' | 'miss' | 'heal' | 'info' };
type Anim = { unit: string; kind: 'lunge' | 'hit' | 'crit' | 'heal'; n: number };

const MONSTER_GLYPH: [RegExp, string][] = [
    [/wolf|hound|fang/i, '🐺'],
    [/goblin|kobold/i, '👺'],
    [/spider/i, '🕷️'],
    [/slime|ooze|jelly/i, '🟢'],
    [/bat/i, '🦇'],
    [/rat/i, '🐀'],
    [/boar|bear/i, '🐗'],
    [/skeleton|bone|lich|wight|ghoul|zombie|crypt/i, '💀'],
    [/ghost|wraith|spirit|shade/i, '👻'],
    [/golem|stone|rock|elemental/i, '🗿'],
    [/troll|ogre|giant|yeti/i, '👹'],
    [/drake|dragon|wyrm|salamander/i, '🐉'],
    [/bandit|thief|brigand|cultist|miner|knight/i, '🥷'],
    [/imp|demon|fiend/i, '😈'],
    [/treant|ent|thorn|vine/i, '🌳'],
    [/harpy|bird|raven|owl/i, '🦅'],
    [/beetle|crawler|worm/i, '🪲'],
];

function monsterGlyph(name: string): string {
    return MONSTER_GLYPH.find(([re]) => re.test(name))?.[1] ?? '👾';
}

function titleCase(code?: string): string {
    return (code ?? 'potion').split('_').map((w) => w[0]?.toUpperCase() + w.slice(1)).join(' ');
}

function buildFrames(enc: Encounter, heroName: string): Frame[] {
    const frames: Frame[] = [];
    let cur: Frame = { hero: enc.hp_before, monsters: Object.fromEntries(enc.units.map((u) => [u.name, u.hp])) };
    frames.push(cur);
    for (const ev of enc.log) {
        cur = { hero: cur.hero, monsters: { ...cur.monsters } };
        if (ev.ev === 'hit' && ev.t !== undefined && ev.thp !== undefined) {
            if (ev.t === heroName) cur.hero = ev.thp;
            else if (ev.t in cur.monsters) cur.monsters[ev.t] = ev.thp;
            if (ev.a === heroName && ev.ahp !== undefined) cur.hero = ev.ahp;
        } else if ((ev.ev === 'potion' || ev.ev === 'potion_topup') && ev.thp !== undefined) {
            cur.hero = ev.thp;
        }
        frames.push(cur);
    }
    return frames;
}

function narrate(ev: LogEvent, heroName: string, labels: Record<string, string>): { text: string; tone: string } {
    const n = (x?: string) => (x === heroName ? heroName : labels[x ?? ''] ?? x ?? '?');
    switch (ev.ev) {
        case 'hit': {
            const heroHit = ev.a === heroName;
            return {
                text: `${n(ev.a)} ${ev.crit ? 'lands a CRITICAL hit on' : 'hits'} ${n(ev.t)} for ${ev.dmg}.${ev.heal ? ` Lifesteal +${ev.heal} HP.` : ''}${ev.thp === 0 ? ` ${n(ev.t)} falls!` : ''}`,
                tone: ev.crit ? 'text-lantern-300 font-semibold' : heroHit ? 'text-ink-100' : 'text-blood-400',
            };
        }
        case 'dodge':
            return { text: `${n(ev.t)} dodges ${n(ev.a)}'s attack.`, tone: 'text-frost-400' };
        case 'potion':
            return { text: `${heroName} drinks a ${titleCase(ev.code)} (+${ev.heal} HP).`, tone: 'text-moss-400' };
        case 'potion_topup':
            return { text: `Catching breath, ${heroName} drinks a ${titleCase(ev.code)} (+${ev.heal} HP).`, tone: 'text-moss-400' };
        case 'flee':
            return { text: `${heroName} escapes! (${Math.round((ev.p ?? 0) * 100)}% chance)`, tone: 'text-lantern-300 font-semibold' };
        case 'flee_fail':
            return { text: `${heroName} tries to flee but is cut off (${Math.round((ev.p ?? 0) * 100)}% chance).`, tone: 'text-lantern-500' };
        case 'left_early':
            return { text: `Too hurt to press on, ${heroName} turns back toward Lanternford.`, tone: 'text-lantern-300' };
        case 'loot':
            return { text: `${n(ev.t)} drops ${ev.name}.`, tone: 'text-lantern-200 font-semibold' };
        case 'mat':
            return { text: `${n(ev.t)} drops ${ev.name} ×${ev.qty}.`, tone: 'text-frost-400' };
        default:
            return { text: JSON.stringify(ev), tone: 'text-ink-400' };
    }
}

const RESULT_BANNER: Record<string, { text: string; tone: string }> = {
    won: { text: 'Victory', tone: 'text-moss-400' },
    lost: { text: 'Defeated', tone: 'text-blood-400' },
    fled: { text: 'Escaped', tone: 'text-lantern-300' },
    exhausted: { text: 'Exhausted', tone: 'text-lantern-300' },
    left_early: { text: 'Turned back', tone: 'text-lantern-300' },
};

export default function TripPage({ trip }: { trip: Trip }) {
    const tz = useTimezone();
    const encounters = trip.encounters;
    const [enc, setEnc] = useState(0);
    const [step, setStep] = useState(0);
    const [playing, setPlaying] = useState(encounters.length > 0);
    const [speed, setSpeed] = useState<(typeof SPEEDS)[number]>(1);
    const [finished, setFinished] = useState(encounters.length === 0);
    const [popups, setPopups] = useState<Popup[]>([]);
    const [anims, setAnims] = useState<Record<string, Anim>>({});
    const popupId = useRef(1);
    const logRef = useRef<HTMLDivElement>(null);

    const current = encounters[enc];
    const frames = useMemo(() => (current ? buildFrames(current, trip.hero) : []), [current, trip.hero]);
    const frame = frames[Math.min(step, frames.length - 1)];
    const encDone = current ? step >= current.log.length : true;
    const revealedThrough = finished ? Number.POSITIVE_INFINITY : encDone ? (current?.seq ?? 0) : (encounters[enc - 1]?.seq ?? 0);
    const revealedLoot = trip.loot.filter((l) => l.encounter_seq !== null && l.encounter_seq <= revealedThrough);
    const revealedFights = encounters.filter((e) => e.seq <= revealedThrough);
    const shownXp = finished ? trip.xp : revealedFights.reduce((sum, e) => sum + e.xp, 0);
    const shownGold = finished ? trip.gold : revealedFights.reduce((sum, e) => sum + e.gold, 0);

    const fire = useCallback(
        (ev: LogEvent) => {
            const unitOf = (name?: string) => (name === trip.hero ? HERO : name ?? '');
            const pops: Omit<Popup, 'id'>[] = [];
            const next: Record<string, Anim> = {};
            const bumpAnim = (unit: string, kind: Anim['kind']) => {
                next[unit] = { unit, kind, n: (anims[unit]?.n ?? 0) + 1 };
            };
            if (ev.ev === 'hit') {
                bumpAnim(unitOf(ev.a), 'lunge');
                bumpAnim(unitOf(ev.t), ev.crit ? 'crit' : 'hit');
                pops.push({ unit: unitOf(ev.t), text: ev.crit ? `${ev.dmg}!` : `-${ev.dmg}`, kind: ev.crit ? 'crit' : 'dmg' });
                if (ev.heal) pops.push({ unit: unitOf(ev.a), text: `+${ev.heal}`, kind: 'heal' });
            } else if (ev.ev === 'dodge') {
                bumpAnim(unitOf(ev.a), 'lunge');
                pops.push({ unit: unitOf(ev.t), text: 'MISS', kind: 'miss' });
            } else if (ev.ev === 'potion' || ev.ev === 'potion_topup') {
                bumpAnim(HERO, 'heal');
                pops.push({ unit: HERO, text: `+${ev.heal}`, kind: 'heal' });
            } else if (ev.ev === 'flee') {
                pops.push({ unit: HERO, text: 'ESCAPE', kind: 'info' });
            } else if (ev.ev === 'flee_fail') {
                pops.push({ unit: HERO, text: 'BLOCKED', kind: 'info' });
            } else if (ev.ev === 'loot') {
                pops.push({ unit: unitOf(ev.t), text: '✦ LOOT', kind: 'info' });
            } else if (ev.ev === 'mat') {
                pops.push({ unit: unitOf(ev.t), text: ev.name ?? 'material', kind: 'info' });
            }
            if (Object.keys(next).length) setAnims((a) => ({ ...a, ...next }));
            if (pops.length) {
                const withIds = pops.map((p) => ({ ...p, id: popupId.current++ }));
                setPopups((p) => [...p, ...withIds]);
                window.setTimeout(() => setPopups((p) => p.filter((x) => !withIds.some((w) => w.id === x.id))), 1100);
            }
        },
        [anims, trip.hero],
    );

    const advance = useCallback(() => {
        if (!current) return;
        if (step < current.log.length) {
            fire(current.log[step]);
            setStep((s) => s + 1);
        } else if (enc < encounters.length - 1) {
            setEnc((e) => e + 1);
            setStep(0);
        } else {
            setPlaying(false);
            setFinished(true);
        }
    }, [current, step, enc, encounters.length, fire]);

    useEffect(() => {
        if (!playing) return;
        const delay = (encDone ? STEP_MS * 2.2 : STEP_MS) / speed;
        const timer = window.setTimeout(advance, delay);
        return () => clearTimeout(timer);
    }, [playing, advance, encDone, speed]);

    useEffect(() => {
        logRef.current?.scrollTo({ top: logRef.current.scrollHeight, behavior: 'smooth' });
    }, [step, enc]);

    const jump = (i: number) => {
        setEnc(i);
        setStep(0);
        setFinished(false);
        setPopups([]);
    };
    const skipFight = () => {
        if (current) setStep(current.log.length);
    };
    const skipAll = () => {
        setEnc(encounters.length - 1);
        setStep(encounters[encounters.length - 1]?.log.length ?? 0);
        setPlaying(false);
        setFinished(true);
    };
    const restart = () => {
        jump(0);
        setPlaying(true);
    };

    const outcome = OUTCOME_META[trip.outcome];
    const leveled = trip.level_after > trip.level_before;
    const liveMonsters = current?.units ?? [];
    const heroPct = frame ? frame.hero / trip.max_hp : 1;

    return (
        <>
            <Head title={`${trip.place.name} replay`} />
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <Link href={trip.mail_id ? `/mail/${trip.mail_id}` : '/mail'} className="btn-ghost">
                    <ChevronLeft className="size-4" /> {trip.mail_id ? 'Back to report' : 'Mail'}
                </Link>
                <div className="text-xs text-ink-400">Run #{trip.run_id} · {formatDateTime(trip.created_at, tz)}</div>
            </div>

            <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <div className="flex items-center gap-3">
                        <span className="text-4xl">{PLACE_ICONS[trip.place.type] ?? '🗺️'}</span>
                        <div>
                            <h1 className="text-3xl font-extrabold text-lantern-200 text-shadow-glow">{trip.place.name}</h1>
                            <p className="text-sm text-ink-400">
                                {trip.hero} · Level {trip.level_before} · {trip.encounters_won}/{trip.encounters_planned} fights won
                                {trip.buffs.length > 0 && <> · buffs: {trip.buffs.join(', ')}</>}
                            </p>
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <OutcomeChip outcome={trip.outcome} />
                    {trip.boss_killed && <span className="chip border-rarity-legendary/50 bg-rarity-legendary/10 text-rarity-legendary"><Crown className="size-3" /> Boss slain</span>}
                </div>
            </div>

            {encounters.length > 0 && current && frame && (
                <>
                    <div className="scroll-thin mb-4 flex gap-1.5 overflow-x-auto pb-1">
                        {encounters.map((e, i) => {
                            const tone =
                                e.result === 'won' ? 'border-moss-500/50' : e.result === 'lost' ? 'border-blood-500/60' : 'border-lantern-500/50';
                            return (
                                <button
                                    key={e.seq}
                                    onClick={() => jump(i)}
                                    className={clsx(
                                        'flex shrink-0 items-center gap-1.5 rounded-lg border bg-ink-900/70 px-3 py-1.5 text-xs font-semibold transition',
                                        tone,
                                        i === enc ? 'bg-lantern-400/15 text-lantern-200 ring-1 ring-lantern-400' : 'text-ink-300 hover:bg-ink-800',
                                        i > enc && !finished && 'opacity-50',
                                    )}
                                >
                                    {e.is_boss ? <Skull className="size-3.5 text-blood-400" /> : <span className="tabular-nums">{e.seq}</span>}
                                    {e.units.length > 0 ? `${e.units[0].base}${e.units.length > 1 ? ` ×${e.units.length}` : ''}` : 'Retreat'}
                                </button>
                            );
                        })}
                    </div>

                    <div
                        className={clsx(
                            'relative overflow-hidden rounded-2xl border bg-gradient-to-b p-4 shadow-2xl sm:p-8',
                            current.is_boss ? 'border-blood-600/50 from-blood-600/15 via-ink-900 to-ink-950' : 'border-ink-700 from-ink-800 via-ink-900 to-ink-950',
                        )}
                    >
                        <div className="pointer-events-none absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-black/50 to-transparent" />
                        <div className="relative mb-4 flex items-center justify-between">
                            <div className="font-display text-sm tracking-[0.2em] text-ink-300 uppercase">
                                {current.is_boss ? '☠ Boss encounter' : `Encounter ${current.seq}`}
                            </div>
                            <div className="font-display text-sm text-ink-400 tabular-nums">
                                Round {current.log[Math.max(0, step - 1)]?.r ?? 1}
                                {current.rounds > 0 && ` / ${current.rounds}`}
                            </div>
                        </div>

                        <div className="relative grid min-h-64 grid-cols-[1fr_auto_1fr] items-center gap-4 sm:gap-10">
                            <Fighter
                                name={trip.hero}
                                glyph={trip.derived?.damage_type === 'magic' ? '🧙' : '🛡️'}
                                hp={frame.hero}
                                maxHp={trip.max_hp}
                                side="left"
                                anim={anims[HERO]}
                                popups={popups.filter((p) => p.unit === HERO)}
                                hero
                                low={heroPct < 0.3}
                            />
                            <div className="font-display text-2xl font-extrabold text-ink-600 sm:text-4xl">VS</div>
                            <div className={clsx('grid gap-3', liveMonsters.length > 2 ? 'grid-cols-2' : 'grid-cols-1')}>
                                {liveMonsters.length === 0 && <div className="text-center text-sm text-ink-400 italic">The trail home…</div>}
                                {liveMonsters.map((u: Unit) => (
                                    <Fighter
                                        key={u.name}
                                        name={u.label}
                                        level={u.level}
                                        glyph={monsterGlyph(u.base)}
                                        hp={frame.monsters[u.name] ?? 0}
                                        maxHp={u.hp}
                                        side="right"
                                        boss={u.is_boss}
                                        compact={liveMonsters.length > 1}
                                        anim={anims[u.name]}
                                        popups={popups.filter((p) => p.unit === u.name)}
                                    />
                                ))}
                            </div>
                        </div>

                        {encDone && (
                            <div className="pointer-events-none absolute inset-0 grid place-items-center">
                                <div className="animate-rise rounded-2xl border border-ink-600/60 bg-ink-950/80 px-10 py-5 text-center backdrop-blur-sm">
                                    <div className={clsx('font-display text-4xl font-extrabold', RESULT_BANNER[current.result]?.tone ?? 'text-ink-100')}>
                                        {RESULT_BANNER[current.result]?.text ?? current.result}
                                    </div>
                                    {(current.xp > 0 || current.gold > 0) && (
                                        <div className="mt-2 flex items-center justify-center gap-4 text-sm">
                                            <span className="font-semibold text-arcane-400">+{current.xp} XP</span>
                                            <Gold amount={current.gold} />
                                        </div>
                                    )}
                                    {trip.loot.filter((l) => l.encounter_seq === current.seq).map((l) => (
                                        <div key={l.id} className={clsx('mt-1 flex items-center justify-center gap-2 text-sm', RARITY_TEXT[l.rarity])}>
                                            <ItemGlyph icon={l.icon} className="size-5" />
                                            <RarityName name={l.name} rarity={l.rarity} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="mt-3 flex flex-wrap items-center justify-center gap-2">
                        <button className="btn-ghost" onClick={restart} title="Restart"><RotateCcw className="size-4" /></button>
                        <button className="btn-primary w-28" onClick={() => (finished ? restart() : setPlaying((p) => !p))}>
                            {playing ? <><Pause className="size-4" /> Pause</> : <><Play className="size-4" /> {finished ? 'Replay' : 'Play'}</>}
                        </button>
                        <button className="btn-ghost" onClick={() => { setPlaying(false); advance(); }} disabled={finished} title="Step">
                            <StepForward className="size-4" />
                        </button>
                        <button className="btn-ghost" onClick={skipFight} disabled={encDone} title="Skip this fight">
                            <SkipForward className="size-4" />
                        </button>
                        <button className="btn-ghost" onClick={skipAll} disabled={finished} title="Skip to results">
                            <ChevronsRight className="size-4" />
                        </button>
                        <div className="ml-2 flex items-center rounded-lg border border-ink-700 bg-ink-900/60 p-0.5">
                            <FastForward className="mx-1.5 size-3.5 text-ink-400" />
                            {SPEEDS.map((s) => (
                                <button
                                    key={s}
                                    onClick={() => setSpeed(s)}
                                    className={clsx('rounded-md px-2 py-1 text-xs font-bold', speed === s ? 'bg-lantern-400 text-on-accent' : 'text-ink-300')}
                                >
                                    {s}×
                                </button>
                            ))}
                        </div>
                    </div>
                </>
            )}

            <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Panel title="Battle log" className="lg:col-span-2" bodyClass="p-0">
                    <div ref={logRef} className="scroll-thin h-72 space-y-1 overflow-y-auto px-4 py-3 font-mono text-[13px]">
                        {encounters.length === 0 && <p className="text-ink-400">No fights on this trip.</p>}
                        {encounters.slice(0, enc + 1).map((e, i) => {
                            const lbl = Object.fromEntries(e.units.map((u) => [u.name, u.label]));
                            const shown = i < enc || finished ? e.log : e.log.slice(0, step);
                            return (
                                <div key={e.seq}>
                                    <div className="mt-2 mb-1 text-[11px] font-bold tracking-wider text-ink-500 uppercase first:mt-0">
                                        — {e.is_boss ? 'Boss: ' : `Fight ${e.seq}: `}
                                        {e.units.map((u) => u.label).join(', ') || 'retreat'} —
                                    </div>
                                    {shown.map((ev, j) => {
                                        const line = narrate(ev, trip.hero, lbl);
                                        return (
                                            <div key={j} className={clsx('animate-rise', line.tone)}>
                                                {ev.r !== undefined && <span className="mr-2 text-ink-600 tabular-nums">R{ev.r}</span>}
                                                {line.text}
                                            </div>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                </Panel>

                <Panel title="Spoils">
                    {!finished && encounters.length > 0 && !encDone && enc === 0 ? (
                        <div className="py-6 text-center text-sm text-ink-400">
                            <div className="mb-2 text-3xl opacity-60">🎁</div>
                            Drops show up as each fight ends.
                        </div>
                    ) : (
                        <div className="animate-rise space-y-4">
                            {finished && <div className={clsx('text-center font-display text-3xl font-extrabold', outcome.tone)}>{outcome.label}</div>}
                            <div className="grid grid-cols-2 gap-2 text-center">
                                <div className="rounded-lg bg-ink-900/60 p-2">
                                    <div className="text-[11px] text-ink-400 uppercase">Experience</div>
                                    <div className="text-lg font-bold text-arcane-400">+{shownXp}</div>
                                </div>
                                <div className="rounded-lg bg-ink-900/60 p-2">
                                    <div className="text-[11px] text-ink-400 uppercase">Gold</div>
                                    <Gold amount={shownGold} className="text-lg" />
                                </div>
                            </div>
                            {finished && leveled && (
                                <div className="animate-glow rounded-lg border border-arcane-400/50 bg-arcane-500/10 p-3 text-center font-display font-bold text-arcane-400">
                                    ✨ Level {trip.level_before} → {trip.level_after}
                                </div>
                            )}
                            {finished && (
                                <div>
                                    <div className="label">Hit points</div>
                                    <Bar value={trip.hp_end} max={trip.max_hp} tone={trip.hp_end / trip.max_hp > 0.5 ? 'moss' : 'blood'} label={`${trip.hp_end} / ${trip.max_hp}`} className="h-4" />
                                </div>
                            )}
                            <div>
                                <div className="label">Loot</div>
                                {revealedLoot.length === 0 && !(finished && trip.chest) ? (
                                    <p className="text-sm text-ink-500">Nothing worth carrying.</p>
                                ) : (
                                    <ul className="space-y-1.5">
                                        {revealedLoot.map((l, i) => (
                                            <li key={l.id} className="flex animate-rise items-center justify-between gap-2 text-sm" style={{ animationDelay: `${i * 120}ms` }}>
                                                <span className={clsx('flex min-w-0 items-center gap-2', RARITY_TEXT[l.rarity])}>
                                                    <ItemGlyph icon={l.icon} className="size-5 shrink-0" />
                                                    <RarityName name={l.name} rarity={l.rarity} className="truncate" />
                                                </span>
                                                <span className="shrink-0 text-[11px] text-ink-500">
                                                    {l.encounter_seq === null ? 'chest' : `fight ${l.encounter_seq}`}
                                                    {!l.kept && ' · left behind'}
                                                </span>
                                            </li>
                                        ))}
                                        {finished && trip.loot.filter((l) => l.encounter_seq === null).map((l) => (
                                            <li key={l.id} className="flex items-center justify-between gap-2 text-sm">
                                                <span className={clsx('flex min-w-0 items-center gap-2', RARITY_TEXT[l.rarity])}>
                                                    <ItemGlyph icon={l.icon} className="size-5 shrink-0" />
                                                    <RarityName name={l.name} rarity={l.rarity} className="truncate" />
                                                </span>
                                                <span className="shrink-0 text-[11px] text-ink-500">chest{!l.kept && ' · left behind'}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {finished && trip.chest?.status === 'sealed' && (
                                    <Link href="/character?tab=inventory" className="mt-3 flex items-center justify-between gap-2 rounded-lg border border-lantern-500/40 bg-lantern-500/10 px-3 py-2 text-sm">
                                        <span>🧰 Sealed chest · {trip.chest.rolls} {trip.chest.rolls === 1 ? 'roll' : 'rolls'}</span>
                                        <span className="text-lantern-300">Open</span>
                                    </Link>
                                )}
                            </div>
                            {finished && trip.mail_id && (
                                <Link href={`/mail/${trip.mail_id}`} className="btn-ghost w-full"><Mail className="size-4" /> Read the report</Link>
                            )}
                        </div>
                    )}
                </Panel>
            </div>
        </>
    );
}

function Fighter({ name, glyph, hp, maxHp, side, anim, popups, hero, boss, compact, level, low }: {
    name: string;
    glyph: string;
    hp: number;
    maxHp: number;
    side: 'left' | 'right';
    anim?: Anim;
    popups: Popup[];
    hero?: boolean;
    boss?: boolean;
    compact?: boolean;
    level?: number | null;
    low?: boolean;
}) {
    const dead = hp <= 0;
    const animClass =
        anim?.kind === 'lunge'
            ? side === 'left' ? 'animate-lunge-right' : 'animate-lunge-left'
            : anim?.kind === 'hit'
              ? 'animate-shake'
              : anim?.kind === 'crit'
                ? 'animate-shake [&_.glyph]:animate-flash'
                : anim?.kind === 'heal'
                  ? '[&_.glyph]:animate-flash'
                  : '';
    const pct = maxHp > 0 ? hp / maxHp : 0;

    return (
        <div className={clsx('relative flex flex-col items-center transition-opacity duration-500', dead && 'opacity-30 grayscale')}>
            <div key={anim ? `${anim.kind}-${anim.n}` : 'idle'} className={clsx('flex flex-col items-center', animClass)}>
                <div
                    className={clsx(
                        'glyph relative grid place-items-center rounded-full border-2 bg-gradient-to-b from-ink-700 to-ink-900 shadow-xl',
                        compact ? 'size-16 text-4xl' : 'size-24 text-6xl sm:size-28',
                        hero ? 'border-lantern-400/70' : boss ? 'border-blood-500 shadow-[0_0_30px_-6px_var(--color-blood-500)]' : 'border-ink-500',
                        low && !dead && 'animate-pulse',
                    )}
                >
                    <span className={clsx(side === 'right' && '-scale-x-100', 'inline-block')}>{dead ? '💀' : glyph}</span>
                    {boss && <Crown className="absolute -top-3 size-6 text-rarity-legendary drop-shadow" />}
                </div>
            </div>
            <div className={clsx('mt-2 text-center', compact ? 'w-28' : 'w-36 sm:w-44')}>
                <div className={clsx('truncate text-sm font-semibold', hero ? 'text-lantern-200' : boss ? 'text-blood-400' : 'text-ink-100')}>
                    {name}
                    {level != null && <span className="ml-1 text-[11px] font-normal text-ink-400">Lv{level}</span>}
                </div>
                <Bar
                    value={hp}
                    max={maxHp}
                    tone={hero ? (pct > 0.5 ? 'moss' : pct > 0.25 ? 'lantern' : 'blood') : 'blood'}
                    className="mt-1 h-3"
                    label={`${Math.max(0, hp)} / ${maxHp}`}
                />
            </div>
            {popups.map((p) => (
                <span
                    key={p.id}
                    className={clsx(
                        'pointer-events-none absolute top-2 left-1/2 animate-float-up font-display font-extrabold whitespace-nowrap drop-shadow-[0_2px_4px_rgb(0_0_0/0.8)]',
                        p.kind === 'crit' && 'text-4xl text-lantern-300',
                        p.kind === 'dmg' && 'text-2xl text-blood-400',
                        p.kind === 'miss' && 'text-xl text-frost-400',
                        p.kind === 'heal' && 'text-2xl text-moss-400',
                        p.kind === 'info' && 'text-lg text-lantern-200',
                    )}
                >
                    {p.text}
                </span>
            ))}
        </div>
    );
}
