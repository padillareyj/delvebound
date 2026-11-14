import { ContactShadows, Html, OrbitControls, useAnimations, useGLTF } from '@react-three/drei';
import { Canvas, useFrame } from '@react-three/fiber';
import { Suspense, useMemo, useRef } from 'react';
import { Box3, Group, Vector3 } from 'three';
import { clone as cloneSkinned } from 'three/examples/jsm/utils/SkeletonUtils.js';
import { CameraRig, Ground, HitBurst, Lunge, Motes, Props, THEMES, themeFor } from '@/components/BattleArena';
import type { HeroLook } from '@/types';
import { Q_LOOKS, QuaterniusHero, gearOf, useCueAnimations, type Cue, type Gear, type QTint } from '@/components/CharacterModel';

/*
 * Client-side 3D battle replay. It only draws what the Trip page already replays from the stored log:
 * the page owns the step/turn state and passes HP, cues (attack/hit/death), and floating numbers here.
 * Monsters: Quaternius Ultimate Monsters, Animated Enemies, and Animated Animal Pack (CC0), via poly.pizza;
 * Zombie by bachosoftdesign (CC-BY 3.0), via poly.pizza.
 */

const M = '/models/monsters';

type Spec =
    | { glb: string; h?: number }
    | { look: keyof typeof Q_LOOKS; tint: QTint; scale?: number; weapon?: [string, boolean?]; offhand?: string; hair?: string[] };

const hum = (look: keyof typeof Q_LOOKS, tint: QTint, scale = 1, weapon?: [string, boolean?], offhand?: string, hair?: string[]): Spec =>
    ({ look, tint, scale, weapon, offhand, hair });

// Shared tints for the humanoid kit (colours multiply the painted textures).
const ZOMBIE = '#8fa894';
const GHOST: QTint = { cloth: '#a8cbe0', skin: '#d6ecff', hair: '#d6ecff', emissive: '#2b4f73', opacity: 0.55 };

/**
 * First match wins; tested against the monster's base name. Humanoid foes reuse the hero's realistic
 * Quaternius kit (recoloured, rescaled, with gear); beasts and odd shapes use animated Quaternius models.
 */
const MONSTER_SPECS: [RegExp, Spec][] = [
    // Undead
    [/bone lord/i, { glb: 'Skeleton' }],
    [/skeleton|bone|burial|barrow/i, { glb: 'Skeleton' }],
    [/citadel wraith/i, hum('ranger', { ...GHOST, cloth: '#2b2b33', skin: '#9aa3b5', emissive: '#4a0d0d', opacity: 0.7 }, 1.05, ['sword'])],
    [/zombie|drowned|wretch|ghoul|undead|rotting|corpse|revenant/i, { glb: 'Zombie', h: 1.35 }],
    [/tide reaver/i, hum('ranger', { skin: ZOMBIE, cloth: '#2f4f5a' }, 1.1, ['greataxe', true])],
    [/wraith|ghost|shimmer|drifter|mirage|heat\b/i, hum('ranger', GHOST, 1)],
    [/siren/i, hum('huntress', { ...GHOST, cloth: '#7fe3f0', emissive: '#1d6f80' }, 1, undefined, undefined, ['Hair_Long'])],
    // Beasts
    [/rat\b/i, { glb: 'Rat' }],
    [/wolf|hound/i, { glb: 'Wolf' }],
    [/boar/i, { glb: 'Bull' }],
    [/spider/i, { glb: 'Spider' }],
    [/bat\b/i, { glb: 'Bat' }],
    [/wasp|scarab|hopper|skimmer/i, { glb: 'Armabee' }],
    [/leech|eel|snake|viper|serpent|asp\b|tendril|stalk/i, { glb: 'Snake' }],
    [/phoenix|sandking|dunelord/i, { glb: 'Dragon_Evolved' }],
    [/drake|dragon/i, { glb: 'Dragon' }],
    [/motile|ooze|slime|blob/i, { glb: 'Slime', h: 0.6 }],
    // Goblinoids and brutes
    [/goblin/i, hum('fighter', { skin: '#7a9a4e', hair: '#5d7a3a', cloth: '#8a7a5a' }, 0.72, ['dagger'], undefined, ['Hair_Buzzed'])],
    [/kobold foreman/i, hum('fighter', { skin: '#b0704a', hair: '#6b3d22', cloth: '#5a4a30' }, 0.75, ['maul', true], undefined, ['Hair_Buzzed'])],
    [/kobold/i, hum('fighter', { skin: '#b0704a', hair: '#6b3d22', cloth: '#6b5a3a' }, 0.65, ['mace'], undefined, ['Hair_Buzzed'])],
    [/yeti/i, hum('fighter', { skin: '#cfd8dc', cloth: '#e6e6e6', hair: '#ffffff' }, 1.45, undefined, undefined, ['Hair_Long', 'Hair_Beard'])],
    [/obsidian golem/i, hum('fighter', { skin: '#55555f', cloth: '#3a3a44', hair: '#2a2a33', emissive: '#5a1500' }, 1.45, ['maul', true], undefined, ['Hair_Buzzed'])],
    [/golem|sentinel|gargoyle|monolith|slab|dunehulk|kilnkeeper/i, hum('fighter', { skin: '#7a7670', cloth: '#5e5a55', hair: '#6e6a64' }, 1.35, ['maul', true], undefined, ['Hair_Buzzed'])],
    [/glass|shardling/i, hum('huntress', { cloth: '#bff3ff', skin: '#e0fbff', hair: '#bff3ff', emissive: '#2a8fa8', opacity: 0.6 }, 0.85, ['dagger'])],
    [/dust|simoom|sirocco/i, { glb: 'Hywirl' }],
    // Casters and clergy
    [/ice witch/i, hum('mage', { cloth: '#9cc7e6', hair: '#f4fbff', skin: '#e8f2f8' }, 1, ['staff'])],
    [/witch|hag/i, hum('mage', { cloth: '#5b3a72', hair: '#c9c9c9', skin: '#b8c4a8' }, 0.95, ['wand'])],
    [/queen/i, hum('huntress', { cloth: '#1f6d73', hair: '#d9f4f2', skin: '#cfe6e2' }, 1.15, ['spear'], undefined, ['Hair_Long'])],
    [/priest|oracle/i, hum('mage', { cloth: '#2e6f73', hair: '#e5e5e5' }, 1, ['staff'])],
    [/cantor|chorister|hymn/i, hum('mage', { cloth: '#6e6e6e', hair: '#3a3a3a' }, 1, ['wand'])],
    [/abbot/i, hum('fighter', { cloth: '#5a1414', hair: '#bbbbbb' }, 1.15, ['staff'])],
    [/matron/i, hum('mage', { cloth: '#2b1d1d', hair: '#1a1a1a', emissive: '#2a0800' }, 1.05, ['staff'])],
    [/acolyte/i, hum('huntress', { cloth: '#a5541c' }, 0.95, ['wand'])],
    [/monk/i, hum('fighter', { cloth: '#7a5230', hair: '#2a2a2a' }, 1, ['staff'])],
    [/cultist/i, hum('ranger', { cloth: '#7a1f1f' }, 1, ['dagger'])],
    [/imp\b|magma/i, hum('fighter', { skin: '#b03a1a', hair: '#2a0a00', cloth: '#3a1a10', emissive: '#4a1200' }, 0.6, undefined, undefined, ['Hair_Buzzed'])],
    // Armoured foes and bosses
    [/ashen sovereign/i, hum('ranger', { skin: '#7d7d7d', cloth: '#262626', emissive: '#4a1800' }, 1.35, ['greatsword', true])],
    [/castellan/i, hum('fighter', { skin: '#8a8a8a', cloth: '#2f2f2f', hair: '#3a3a3a', emissive: '#3a1200' }, 1.25, ['maul', true])],
    [/ash knight/i, hum('ranger', { cloth: '#3a3a3a', skin: '#9a9a9a' }, 1.1, ['sword'], 'tower')],
    [/guard/i, hum('ranger', { cloth: '#56657f' }, 1.05, ['spear'])],
    [/warden/i, hum('fighter', { cloth: '#4a5a4a', hair: '#2a2a2a' }, 1.2, ['maul', true])],
    [/demon|fiend/i, { glb: 'Blue_Demon' }],
    // Keep the cartoon set where nothing better fits
    [/king of|bloomking|mothercap|host of|colony of|fruiting of/i, { glb: 'Mushroom_King' }],
    [/puff|cap|spore|truffle|bloom|bolete|sprout|pollen/i, { glb: 'Mushnub' }],
    [/toad|frog/i, { glb: 'Frog' }],
    [/lurker|glub|fen/i, { glb: 'Glub' }],
    [/qanat|squid|tide/i, { glb: 'Squidle' }],
    [/runner/i, { glb: 'Dino' }],
];
/** Anything unmatched becomes a generic bandit from the humanoid kit. */
const FALLBACK: Spec = hum('fighter', { cloth: '#4a3f35' }, 1, ['sword'], undefined, ['Hair_SimpleParted']);

export function monsterSpec(base: string): Spec {
    return MONSTER_SPECS.find(([re]) => re.test(base))?.[1] ?? FALLBACK;
}

export interface Popup3D { id: number; unit: string; text: string; kind: 'dmg' | 'crit' | 'miss' | 'heal' | 'info' }
export interface Fighter3D { id: string; label: string; base: string; hp: number; maxHp: number; boss?: boolean; cue?: Cue }

const POP_COLOR: Record<Popup3D['kind'], string> = {
    dmg: '#f87171', crit: '#fcd34d', miss: '#7dd3fc', heal: '#4ade80', info: '#fde68a',
};

function Overlay({ y, label, hp, maxHp, hero, popups, compact }: { y: number; label: string; hp: number; maxHp: number; hero?: boolean; popups: Popup3D[]; compact?: boolean }) {
    const pct = maxHp > 0 ? Math.max(0, Math.min(1, hp / maxHp)) : 0;
    const tone = hero ? (pct > 0.5 ? '#4ade80' : pct > 0.25 ? '#fbbf24' : '#ef4444') : '#ef4444';
    return (
        <Html position={[0, y, 0]} center zIndexRange={[20, 0]} style={{ pointerEvents: 'none' }}>
            <div style={{ width: compact ? 92 : 120, textAlign: 'center', fontFamily: 'inherit', position: 'relative' }}>
                <div style={{ fontSize: compact ? 10.5 : 12, fontWeight: 700, color: hero ? '#fde68a' : '#fff', textShadow: '0 1px 3px #000', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{label}</div>
                <div style={{ height: 8, borderRadius: 4, background: 'rgba(0,0,0,.55)', border: '1px solid rgba(255,255,255,.25)', overflow: 'hidden', marginTop: 2 }}>
                    <div style={{ width: `${pct * 100}%`, height: '100%', background: tone, transition: 'width .35s ease' }} />
                </div>
                {!compact && <div style={{ fontSize: 10, color: '#e5e7eb', textShadow: '0 1px 2px #000' }}>{Math.max(0, hp)} / {maxHp}</div>}
                {popups.map((p) => (
                    <span
                        key={p.id}
                        className="animate-float-up"
                        style={{
                            position: 'absolute', left: '50%', bottom: '100%', transform: 'translateX(-50%)', whiteSpace: 'nowrap',
                            fontWeight: 900, fontSize: p.kind === 'crit' ? 30 : p.kind === 'info' ? 15 : 22, color: POP_COLOR[p.kind],
                            textShadow: '0 2px 4px rgba(0,0,0,.85)',
                        }}
                    >
                        {p.text}
                    </span>
                ))}
            </div>
        </Html>
    );
}

function find(names: string[], ...patterns: RegExp[]): string | undefined {
    for (const re of patterns) {
        const hit = names.find((n) => re.test(n));
        if (hit) return hit;
    }
    return undefined;
}

function GlbMonster({ name, fighter, position, height, speed, popups, lift = 0, compact }: { name: string; fighter: Fighter3D; position: [number, number, number]; height: number; speed: number; popups: Popup3D[]; lift?: number; compact?: boolean }) {
    const group = useRef<Group>(null);
    const { scene, animations } = useGLTF(`${M}/${name}.glb`);
    const { model, scale } = useMemo(() => {
        const copy = cloneSkinned(scene);
        copy.traverse((o) => { o.frustumCulled = false; });
        copy.updateMatrixWorld(true);
        // Skinned bounds (precise) give real-world size; fall back to the plain box when skinning yields nothing.
        let size = new Box3().setFromObject(copy, true).getSize(new Vector3());
        if (![size.x, size.y, size.z].every(Number.isFinite) || size.y <= 0) size = new Box3().setFromObject(copy).getSize(new Vector3());
        // Normalise: tall things by height, long animals by their longest side.
        const k = Math.min(5, height / Math.max(size.y, size.x * 0.7, size.z * 0.7, 0.01));
        return { model: copy, scale: k };
    }, [scene, height]);
    const { actions, names, mixer } = useAnimations(animations, group);
    const short = names.map((n) => n.split('|').pop() ?? n);
    const full = (s?: string) => (s ? names[short.indexOf(s)] : undefined);
    const idle = full(find(short, /^Idle$/, /^Flying_Idle$/, /_Idle$/, /Idle/, /Flying/));
    useCueAnimations(actions, mixer, idle, fighter.cue, (kind) =>
        full(kind === 'attack' ? find(short, /Attack$/, /Bite/, /Headbutt/, /Punch/, /Weapon/, /Attack/)
            : kind === 'hit' ? find(short, /HitRec/, /HitReact$/, /HitReact/, /Hit_reaction/, /Hit/)
                : find(short, /Death$/, /Death/, /^Die$/, /Die/)), speed);

    const hasDeath = short.some((n) => /Death|Die/.test(n));
    const sink = useRef<Group>(null);
    useFrame((_, dt) => {
        if (!sink.current || hasDeath) return;
        const target = fighter.hp <= 0 ? 0.05 : 1;
        sink.current.scale.y += (target - sink.current.scale.y) * Math.min(1, dt * 4 * speed);
    });

    return (
        <group position={position} rotation={[0, -1.3, 0]}>
            <group ref={sink}>
            <group ref={group} scale={scale}>
                <primitive object={model} />
            </group>
            </group>
            <group>
                <Overlay y={height + 0.45 + lift} label={fighter.label} hp={fighter.hp} maxHp={fighter.maxHp} popups={popups} compact={compact} />
            </group>
        </group>
    );
}

function HumanoidMonster({ spec, fighter, position, speed, popups, boss, lift = 0, compact }: {
    lift?: number;
    compact?: boolean;
    spec: Exclude<Spec, { glb: string }>;
    fighter: Fighter3D;
    position: [number, number, number];
    speed: number;
    popups: Popup3D[];
    boss?: boolean;
}) {
    const scale = (spec.scale ?? 1) * (boss ? 1.25 : 1);
    const gear = useMemo(() => {
        const g: Record<string, Gear> = {};
        if (spec.weapon) g.weapon = gearOf(spec.weapon[0], !!spec.weapon[1]);
        if (spec.offhand) g.offhand = gearOf(spec.offhand);
        return g;
    }, [spec]);
    const base = Q_LOOKS[spec.look];
    const look = useMemo(() => ({ ...base, hair: spec.hair ?? base.hair }), [base, spec.hair]);
    return (
        <group position={position} rotation={[0, -1.3, 0]}>
            <group scale={scale}>
                <QuaterniusHero equipment={gear} look={look} tint={spec.tint} cue={fighter.cue} speed={speed} />
            </group>
            <Overlay y={Math.min(1.95 * scale + 0.35, 2.7) + lift} label={fighter.label} hp={fighter.hp} maxHp={fighter.maxHp} popups={popups} compact={compact} />
        </group>
    );
}

function Monster(props: { fighter: Fighter3D; position: [number, number, number]; height: number; speed: number; popups: Popup3D[]; lift?: number; compact?: boolean }) {
    const spec = monsterSpec(props.fighter.base);
    if ('glb' in spec) return <GlbMonster name={spec.glb} {...props} height={props.height * (spec.h ?? 1)} />;
    return <HumanoidMonster spec={spec} fighter={props.fighter} position={props.position} speed={props.speed} popups={props.popups} boss={props.fighter.boss} lift={props.lift} compact={props.compact} />;
}

export default function BattleReplay3D({
    hero, heroEquipment, monsters, popups, speed, fled, boss, place, heroLook,
}: {
    /** The player's saved cosmetic look (characters.look); null keeps the weapon-based default. */
    heroLook?: HeroLook | null;
    hero: Fighter3D;
    heroEquipment: Record<string, Gear>;
    monsters: Fighter3D[];
    popups: Popup3D[];
    speed: number;
    fled?: boolean;
    boss?: boolean;
    place?: { code?: string; name?: string; type?: string };
}) {
    const n = monsters.length;
    const theme = THEMES[themeFor(place, boss)];
    const crowded = n >= 4;
    const heroX = n > 1 ? -2.4 : -1.6;
    const posOf = (i: number): [number, number, number] => (n === 1 ? [1.4, 0, 0] : [0.5 + i * (4.2 / Math.max(n, 3)) * 1.1, 0, i % 2 ? -0.35 : 0.25]);
    // Whoever started the newest attack gets a little of the camera's attention.
    const seen = useRef<Record<string, string>>({});
    const focus = useRef(0);
    [{ f: hero, x: heroX }, ...monsters.map((m, i) => ({ f: m, x: posOf(i)[0] }))].forEach(({ f, x }) => {
        const k = f.cue ? `${f.cue.kind}${f.cue.n}` : '';
        if (seen.current[f.id] !== k) {
            seen.current[f.id] = k;
            if (f.cue?.kind === 'attack') focus.current = x;
        }
    });
    const hitsOf = (id: string) => popups.filter((p) => p.unit === id && (p.kind === 'dmg' || p.kind === 'crit'));
    const lastCrit = [...popups].reverse().find((p) => p.kind === 'crit')?.id;
    return (
        <div style={{ position: 'relative', width: '100%', height: 380 }} className="overflow-hidden rounded-xl">
            <Canvas camera={{ position: [0, 2.2, 6.2], fov: 38 }} dpr={[1, 1.75]}>
                <color attach="background" args={[theme.sky]} />
                <fog attach="fog" args={[theme.sky, ...theme.fog]} />
                <hemisphereLight args={[theme.hemiSky, theme.hemiGround, theme.hemi]} />
                <directionalLight position={[3, 6, 4]} intensity={theme.sunI} color={theme.sun} />
                {/* Rim/back light so dark silhouettes (bosses, ash foes) separate from the background. */}
                <directionalLight position={[-1.5, 3.5, -6]} intensity={theme.rimI} color={theme.rim} />
                <directionalLight position={[4, 2.5, -5]} intensity={theme.rimI * 0.6} color={theme.rim} />
                {(boss || theme === THEMES.ash) && <directionalLight position={[1, 2, 7]} intensity={1.3} color="#ffe2c8" />}
                {boss && <pointLight position={[1.6, 1.4, 2.4]} intensity={6} distance={7} color="#ffd8b0" />}
                <Ground theme={theme} />
                <Props theme={theme} />
                {theme.motes && <Motes color={theme.motes} rise={theme === THEMES.ash || theme === THEMES.boss ? 0.35 : 0.08} />}
                <Suspense fallback={null}>
                    <group position={[heroX, 0, 0]}>
                        <Lunge cue={hero.cue} dir={1} dist={0.8} speed={speed}>
                            <group rotation={[0, fled ? -1.9 : 1.25, 0]}>
                                <QuaterniusHero equipment={heroEquipment} cue={hero.cue} speed={speed} hero={heroLook} />
                                <group rotation={[0, fled ? Math.PI / 2 : -Math.PI / 2, 0]}>
                                    <Overlay y={2.25} label={hero.label} hp={hero.hp} maxHp={hero.maxHp} hero popups={popups.filter((p) => p.unit === hero.id)} />
                                </group>
                            </group>
                        </Lunge>
                        <HitBurst hits={hitsOf(hero.id)} y={1.1} />
                    </group>
                    {monsters.map((m, i) => (
                        <group key={m.id} position={posOf(i)}>
                            <Lunge cue={m.cue} dir={-1} dist={m.boss ? 0.7 : 0.55} speed={speed}>
                                <Monster
                                    fighter={m}
                                    position={[0, 0, 0]}
                                    height={m.boss ? 2.2 : n > 2 ? 1.1 : 1.4}
                                    speed={speed}
                                    popups={popups.filter((p) => p.unit === m.id)}
                                    lift={crowded && i % 2 ? 0.55 : 0}
                                    compact={crowded}
                                />
                            </Lunge>
                            <HitBurst hits={hitsOf(m.id)} y={m.boss ? 1.4 : 0.8} />
                        </group>
                    ))}
                </Suspense>
                <ContactShadows position={[0, 0, 0]} opacity={0.45} scale={12} blur={2.4} far={4} />
                <OrbitControls makeDefault target={[0, 0.9, 0]} enablePan={false} minDistance={3} maxDistance={11} minPolarAngle={0.5} maxPolarAngle={1.45} />
                <CameraRig focus={focus} shakeKey={lastCrit} />
            </Canvas>
        </div>
    );
}
