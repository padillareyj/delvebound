import { ContactShadows, OrbitControls, useAnimations, useGLTF } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { Suspense, useEffect, useMemo, useRef, type ReactNode } from 'react';
import { Color, ConeGeometry, Group, LoopOnce, Mesh, MeshStandardMaterial, Object3D, Skeleton, SkinnedMesh, type AnimationAction, type AnimationClip, type AnimationMixer, type Bone } from 'three';
import { clone as cloneSkinned } from 'three/examples/jsm/utils/SkeletonUtils.js';
import catalogJson from '../../item-art/catalog.json';
import type { HeroLook, Item } from '@/types';

/** The fields the 3D preview needs from an equipped item (Trip pages pass a slimmer shape than the sheet). */
export type Gear = Pick<Item, 'id' | 'code' | 'icon' | 'two_handed'> & Partial<Item>;

/*
 * Proof-of-concept 3D hero preview.
 *
 * Switch the whole character set with CHARACTER_SET:
 *  - 'quaternius': adult-proportioned characters built from Quaternius CC0 packs (Modular Character Outfits -
 *    Fantasy + Universal Base Characters heads/hair + Universal Animation Library idles) holding Quaternius
 *    Medieval Weapons. Files in public/models/quaternius.
 *  - 'kaykit': the original chibi KayKit Adventurers set (public/models/characters + public/models/weapons).
 */
export const CHARACTER_SET: 'quaternius' | 'kaykit' = 'quaternius';

const families = (catalogJson as { families: Record<string, string[]> }).families;
const familyByIcon = new Map<string, string>();
for (const [family, icons] of Object.entries(families)) for (const icon of icons) familyByIcon.set(icon, family);

/** A stand-in item of a given art family, for NPCs/monsters that carry gear in the replay. */
export function gearOf(family: string, twoHanded = false): Gear {
    return { id: family.length * 7919, code: family, icon: families[family]?.[0] ?? '', two_handed: twoHanded };
}

export function familyOf(item?: Gear): string | null {
    if (!item) return null;
    return familyByIcon.get(item.icon) ?? null;
}

/** Stable small hash so the same item always gets the same model variant. */
function pick<T>(list: T[], item: Gear): T {
    let h = 0;
    for (const ch of `${item.code}:${item.id}`) h = (h * 31 + ch.charCodeAt(0)) | 0;
    return list[Math.abs(h) % list.length];
}

const SHIELD_FAMILIES = new Set(['buckler', 'kite', 'tower']);


/** A one-shot cue for battle replays: bump `n` to replay the same kind. */
export type Cue = { kind: 'attack' | 'hit' | 'death'; n: number } | null;

/**
 * Plays `idle` on loop and the cue's clip once, then fades back to idle. Death clamps on its last frame.
 * `clipFor` maps a cue kind to a clip name (or null when the model has none).
 */
export function useCueAnimations(
    actions: Record<string, AnimationAction | null>,
    mixer: AnimationMixer,
    idle: string | undefined,
    cue: Cue | undefined,
    clipFor: (kind: 'attack' | 'hit' | 'death') => string | undefined,
    speed = 1,
) {
    useEffect(() => { mixer.timeScale = speed; }, [mixer, speed]);
    useEffect(() => {
        const base = idle ? actions[idle] : null;
        base?.reset().fadeIn(0.25).play();
        return () => { base?.fadeOut(0.25); };
    }, [actions, idle]);
    const mounted = useRef(false);
    useEffect(() => {
        const first = !mounted.current;
        mounted.current = true;
        if (!cue || (first && cue.kind !== 'death')) return;
        const name = clipFor(cue.kind);
        const shot = name ? actions[name] : null;
        const base = idle ? actions[idle] : null;
        if (!shot) return;
        shot.reset();
        shot.setLoop(LoopOnce, 1);
        shot.clampWhenFinished = true;
        shot.fadeIn(0.08).play();
        if (base && base !== shot) base.fadeOut(0.08);
        if (cue.kind === 'death') return;
        const done = (e: { action: AnimationAction }) => {
            if (e.action !== shot) return;
            mixer.removeEventListener('finished', done);
            shot.fadeOut(0.2);
            base?.reset().fadeIn(0.2).play();
        };
        mixer.addEventListener('finished', done);
        return () => { mixer.removeEventListener('finished', done); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cue?.kind, cue?.n]);
}

/* ------------------------------------------------------------------ Quaternius ----- */

const Q = '/models/quaternius';
type Euler3 = [number, number, number];

const Q_WEAPONS: Record<string, string[]> = {
    sword: ['sword_1h', 'sword_1h_b'],
    cutlass: ['cutlass', 'sword_1h_b'],
    greatsword: ['sword_2h', 'claymore'],
    axe: ['axe_1h'],
    greataxe: ['axe_2h', 'axe_double'],
    mace: ['mace'],
    maul: ['maul'],
    dagger: ['dagger', 'dagger_b'],
    spear: ['spear'],
    staff: ['staff'],
    wand: ['wand'],
    bow: ['bow', 'bow_b', 'bow_gold'],
    crossbow: ['crossbow_1h'],
};
const Q_SHIELDS: Record<string, string[]> = {
    buckler: ['shield_round', 'shield_round_b'],
    kite: ['shield_heater', 'shield_heater_b'],
    tower: ['shield_heater_b', 'shield_heater'],
};

/** Hand-bone offsets, tuned against the UAL idle poses. Weapons are normalised so the grip sits at the origin. */
const GRIP_MELEE: Euler3 = [0, 0, -Math.PI / 2];
const GRIP_BOW: Euler3 = [0, Math.PI / 2, -Math.PI / 2];
const GRIP_CROSSBOW: Euler3 = [0, 0, 0];
const OFFHAND_WEAPON: Euler3 = [0, 0, Math.PI / 2];
const SHIELD_ROUND: Euler3 = [-Math.PI / 2, 0, 0];
const SHIELD_HEATER: Euler3 = [-Math.PI / 2, 0, 2.9];
const SHIELD_POS: Euler3 = [0, 0.16, 0];

export interface QLook {
    outfit: string;
    body: 'Superhero_Male_FullBody' | 'Superhero_Female_FullBody';
    hair: string[];
}
export const Q_LOOKS: Record<string, QLook> = {
    fighter: { outfit: 'Male_Peasant', body: 'Superhero_Male_FullBody', hair: ['Hair_SimpleParted', 'Hair_Beard'] },
    ranger: { outfit: 'Male_Ranger', body: 'Superhero_Male_FullBody', hair: ['Hair_Buzzed'] },
    mage: { outfit: 'Female_Peasant', body: 'Superhero_Female_FullBody', hair: ['Hair_Long'] },
    huntress: { outfit: 'Female_Ranger', body: 'Superhero_Female_FullBody', hair: ['Hair_BuzzedFemale'] },
};

function qLookFor(family: string | null): QLook {
    if (family === 'staff' || family === 'wand') return Q_LOOKS.mage;
    if (family === 'bow' || family === 'dagger') return Q_LOOKS.ranger;
    if (family === 'crossbow' || family === 'spear') return Q_LOOKS.huntress;
    return Q_LOOKS.fighter;
}

// ------------------------------------------------------------------ player-chosen hero look (characters.look)

export const LOOK_HAIR: Record<HeroLook['hair'], string | null> = {
    none: null, simple_parted: 'Hair_SimpleParted', buzzed: 'Hair_Buzzed', buzzed_female: 'Hair_BuzzedFemale', long: 'Hair_Long', buns: 'Hair_Buns',
};
/** Race silhouettes: [width, height, depth] multipliers, before the player's height/build sliders. */
const RACE_SCALE: Record<HeroLook['race'], [number, number, number]> = {
    human: [1, 1, 1], elf: [0.94, 1.05, 0.94], dwarf: [1.18, 0.8, 1.18], orc: [1.14, 1.05, 1.12], undead: [0.92, 1, 0.92],
};
export const RACE_SKIN: Record<HeroLook['race'], string> = {
    human: '#ffffff', elf: '#f6eee6', dwarf: '#f1c9a5', orc: '#9cc47a', undead: '#c8d0c4',
};

/** The weapon-based look every hero had before looks were customisable (also the migration's backfill). */
export function defaultLookFor(equipment: Record<string, Gear>): HeroLook {
    const family = familyOf(equipment.weapon);
    const base = { race: 'human' as const, skin: '#ffffff', hair_color: null, height: 100, build: 100 };
    if (family === 'staff' || family === 'wand') return { ...base, gender: 'female', outfit: 'peasant', hair: 'long', beard: false };
    if (family === 'bow' || family === 'dagger') return { ...base, gender: 'male', outfit: 'ranger_hooded', hair: 'buzzed', beard: false };
    if (family === 'crossbow' || family === 'spear') return { ...base, gender: 'female', outfit: 'ranger_hooded', hair: 'buzzed_female', beard: false };
    return { ...base, gender: 'male', outfit: 'peasant', hair: 'simple_parted', beard: true };
}

function heroParts(h: HeroLook) {
    const g = h.gender === 'female' ? 'Female' : 'Male';
    const hair: string[] = [];
    const style = LOOK_HAIR[h.hair];
    if (style) hair.push(style);
    if (h.gender === 'male' && h.beard) hair.push('Hair_Beard');
    const look: QLook = { outfit: `${g}_${h.outfit === 'peasant' ? 'Peasant' : 'Ranger'}`, body: `Superhero_${g}_FullBody`, hair };
    const tint: QTint = { skin: h.skin, hair: h.hair_color ?? undefined, hairScale: 1.75 };
    const [rx, ry, rz] = RACE_SCALE[h.race] ?? RACE_SCALE.human;
    const k = Math.min(1.2, Math.max(0.85, h.build / 100));
    const tall = Math.min(1.1, Math.max(0.9, h.height / 100));
    return { look, tint, scale: [rx * k, ry * tall, rz * k] as [number, number, number] };
}

/** Pointed ears (elves) and tusks (orcs) are small primitives parented to the head bone; the kit has neither. */
function addRaceBits(rig: Object3D, h: HeroLook) {
    if (h.race !== 'elf' && h.race !== 'orc') return;
    const head = rig.getObjectByName('Head');
    if (!head) return;
    const female = h.gender === 'female';
    if (h.race === 'elf') {
        const skin = new Color('#e8b898').multiply(new Color(h.skin));
        const mat = new MeshStandardMaterial({ color: skin, roughness: 0.7 });
        const geo = new ConeGeometry(0.022, 0.1, 8);
        for (const side of [1, -1]) {
            const ear = new Mesh(geo, mat);
            ear.scale.set(1, 1, 0.35);
            ear.position.set(side * (female ? 0.085 : 0.09), female ? 0.1 : 0.106, -0.018);
            ear.rotation.set(-0.45, 0, -side * 1.15);
            head.add(ear);
        }
    } else {
        const mat = new MeshStandardMaterial({ color: '#efe6cf', roughness: 0.5 });
        const geo = new ConeGeometry(0.0095, 0.042, 6);
        for (const side of [1, -1]) {
            const tusk = new Mesh(geo, mat);
            tusk.position.set(side * 0.017, female ? 0.036 : 0.042, female ? 0.09 : 0.1);
            tusk.rotation.set(0.15, 0, -side * 0.18);
            head.add(tusk);
        }
    }
}

/** Recolour a built rig (used for monsters made from the same humanoid kit). Colours multiply the textures. */
export interface QTint {
    skin?: string;
    cloth?: string;
    hair?: string;
    emissive?: string;
    opacity?: number;
    /** Hair textures are mid-grey; scale the colour up so light shades (blonde, platinum) still read. */
    hairScale?: number;
}

function applyTint(root: Object3D, tint: QTint) {
    root.traverse((o) => {
        const mesh = o as SkinnedMesh;
        if (!mesh.isSkinnedMesh && !(o as { isMesh?: boolean }).isMesh) return;
        const list = Array.isArray(mesh.material) ? mesh.material : [mesh.material];
        const next = list.map((m) => {
            const mat = (m as MeshStandardMaterial).clone();
            const name = mat.name ?? '';
            const colour = /Hair/.test(name) ? tint.hair : /Regular|Superhero/.test(name) ? tint.skin : /Eyes/.test(name) ? undefined : tint.cloth;
            if (colour) mat.color = new Color(colour);
            if (colour && tint.hairScale && /Hair/.test(name)) mat.color.multiplyScalar(tint.hairScale);
            if (tint.emissive) { mat.emissive = new Color(tint.emissive); mat.emissiveIntensity = 0.3; }
            if (tint.opacity !== undefined && tint.opacity < 1) { mat.transparent = true; mat.opacity = tint.opacity; mat.depthWrite = false; }
            return mat;
        });
        mesh.material = Array.isArray(mesh.material) ? next : next[0];
    });
}

const HEAD_BONES = new Set(['Head', 'neck_01']);

/** Keep only the triangles skinned mostly to the head/neck so the base body's face sits inside the outfit. */
function keepHead(mesh: SkinnedMesh) {
    mesh.geometry = mesh.geometry.clone();
    const geo = mesh.geometry;
    const si = geo.attributes.skinIndex;
    const sw = geo.attributes.skinWeight;
    const bones = mesh.skeleton.bones;
    const ok = new Uint8Array(si.count);
    for (let v = 0; v < si.count; v++) {
        let w = 0;
        for (let k = 0; k < 4; k++) if (HEAD_BONES.has(bones[si.getComponent(v, k)]?.name)) w += sw.getComponent(v, k);
        ok[v] = w > 0.5 ? 1 : 0;
    }
    const idx = geo.index!.array;
    const out: number[] = [];
    for (let t = 0; t < idx.length; t += 3) if (ok[idx[t]] && ok[idx[t + 1]] && ok[idx[t + 2]]) out.push(idx[t], idx[t + 1], idx[t + 2]);
    geo.setIndex(out);
}

/** Re-bind skinned meshes from another Quaternius file onto the target rig (same humanoid bone names). */
function graft(source: Object3D, target: Object3D) {
    const byName: Record<string, Bone> = {};
    target.traverse((o) => { if ((o as Bone).isBone) byName[o.name] = o as Bone; });
    const meshes: SkinnedMesh[] = [];
    source.traverse((o) => { if ((o as SkinnedMesh).isSkinnedMesh) meshes.push(o as SkinnedMesh); });
    for (const m of meshes) {
        const bones = m.skeleton.bones.map((b) => byName[b.name] ?? b);
        m.bind(new Skeleton(bones, m.skeleton.boneInverses), m.bindMatrix);
        target.add(m);
    }
}

function QProp({ url, parent, rotation, position }: { url: string; parent?: Object3D; rotation: Euler3; position?: Euler3 }) {
    const { scene } = useGLTF(url);
    const prop = useMemo(() => {
        const pivot = new Group();
        pivot.add(scene.clone(true));
        pivot.rotation.set(...rotation);
        if (position) pivot.position.set(...position);
        return pivot;
    }, [scene, rotation, position]);
    useEffect(() => {
        if (!parent) return;
        parent.add(prop);
        return () => { parent.remove(prop); };
    }, [parent, prop]);
    return null;
}

export function QuaterniusHero({ equipment, cue, speed = 1, look: lookOverride, tint: tintOverride, hero }: {
    /** The player's saved look (characters.look); null/undefined keeps the weapon-based default. */
    hero?: HeroLook | null;
    equipment: Record<string, Gear>;
    cue?: Cue;
    speed?: number;
    /** Force an outfit/head/hair set instead of picking one from the weapon (monsters use this). */
    look?: QLook;
    tint?: QTint;
}) {
    const main = equipment.weapon;
    const off = equipment.offhand;
    const mainFamily = familyOf(main);
    const offFamily = familyOf(off);
    const parts = hero ? heroParts(hero) : null;
    const look = lookOverride ?? parts?.look ?? qLookFor(mainFamily);
    const tint = tintOverride ?? parts?.tint;
    const twoHanded = !!main?.two_handed;

    const outfit = useGLTF(`${Q}/characters/${look.outfit}.glb`);
    const body = useGLTF(`${Q}/characters/${look.body}.glb`);
    const hair = useGLTF(look.hair.map((h) => `${Q}/characters/${h}.glb`));
    const anim = useGLTF(`${Q}/anim.glb`);

    const model = useMemo(() => {
        const rig = cloneSkinned(outfit.scene);
        const head = cloneSkinned(body.scene);
        head.traverse((o) => { if ((o as SkinnedMesh).isSkinnedMesh) keepHead(o as SkinnedMesh); });
        graft(head, rig);
        for (const h of hair) graft(cloneSkinned(h.scene), rig);
        rig.traverse((o) => { o.frustumCulled = false; });
        if (tint) applyTint(rig, tint);
        if (hero) addRaceBits(rig, hero);
        if (hero && hero.outfit !== 'ranger_hooded') rig.traverse((o) => { if (/_Head_Hood/.test(o.name)) o.visible = false; });
        return rig;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [outfit.scene, body.scene, look.hair.join(), JSON.stringify(tint ?? null), hero?.race, hero?.gender, hero?.skin, hero?.outfit]);

    const ranged = mainFamily === 'bow' || mainFamily === 'crossbow';
    const caster = ranged || mainFamily === 'staff' || mainFamily === 'wand';
    const clip = main && !ranged ? 'Sword_Idle' : 'Idle_Loop';
    const clipFor = (kind: 'attack' | 'hit' | 'death') =>
        kind === 'death' ? 'Death01' : kind === 'hit' ? 'Hit_Chest' : !main ? 'Punch_Jab' : caster ? 'Spell_Simple_Shoot' : 'Sword_Attack';

    let weaponUrl: string | null = null;
    let weaponGrip = GRIP_MELEE;
    if (main) {
        const names = (mainFamily && Q_WEAPONS[mainFamily]) || [twoHanded ? 'sword_2h' : 'sword_1h'];
        weaponUrl = `${Q}/weapons/${pick(names, main)}.glb`;
        if (mainFamily === 'bow') weaponGrip = GRIP_BOW;
        if (mainFamily === 'crossbow') weaponGrip = GRIP_CROSSBOW;
    }

    let offUrl: string | null = null;
    let offRot = SHIELD_ROUND;
    let offPos: Euler3 | undefined = SHIELD_POS;
    if (off && !twoHanded) {
        if (!offFamily || SHIELD_FAMILIES.has(offFamily) || !Q_WEAPONS[offFamily]) {
            const name = pick(Q_SHIELDS[offFamily ?? ''] ?? Q_SHIELDS.buckler, off);
            offUrl = `${Q}/weapons/${name}.glb`;
            offRot = name.startsWith('shield_heater') ? SHIELD_HEATER : SHIELD_ROUND;
        } else {
            offUrl = `${Q}/weapons/${pick(Q_WEAPONS[offFamily], off)}.glb`;
            offRot = OFFHAND_WEAPON;
            offPos = undefined;
        }
    }

    const right = model.getObjectByName('hand_r') ?? undefined;
    const left = model.getObjectByName('hand_l') ?? undefined;

    return (
        // Keyed by the rig: a rebuilt rig (new look/outfit/hair) gets a fresh mixer bound to its own bones.
        <QAnimatedRig key={model.uuid} model={model} clips={anim.animations} idle={clip} cue={cue} clipFor={clipFor} speed={speed} scale={parts?.scale ?? 1}>
            {weaponUrl && <QProp url={weaponUrl} parent={right} rotation={weaponGrip} />}
            {offUrl && <QProp url={offUrl} parent={left} rotation={offRot} position={offPos} />}
        </QAnimatedRig>
    );
}

/**
 * Owns the animation mixer for one built rig. The rig stays hidden until the idle clip is applied at full
 * weight, so the bind (T) pose never shows, not on first load and not while the look is being changed.
 */
function QAnimatedRig({ model, clips, idle, cue, clipFor, speed, scale, children }: {
    model: Object3D;
    clips: AnimationClip[];
    idle: string;
    cue?: Cue;
    clipFor: (kind: 'attack' | 'hit' | 'death') => string | undefined;
    speed: number;
    scale: number | [number, number, number];
    children?: ReactNode;
}) {
    const group = useRef<Group>(null);
    const { actions, mixer } = useAnimations(clips, group);
    useCueAnimations(actions, mixer, idle, cue, clipFor, speed);
    useEffect(() => {
        const base = actions[idle];
        if (!base || !group.current) return;
        base.stopFading().setEffectiveWeight(1).play();
        mixer.update(0);
        group.current.visible = true;
    }, [actions, mixer, idle]);
    return (
        <group ref={group} scale={scale} visible={false}>
            <primitive object={model} />
            {children}
        </group>
    );
}

/* ------------------------------------------------------------------ KayKit (legacy) ----- */

const K = '/models';
const K_WEAPONS: Record<string, string> = {
    sword: 'sword_1handed', cutlass: 'sword_1handed', greatsword: 'sword_2handed', axe: 'axe_1handed',
    greataxe: 'axe_2handed', mace: 'axe_1handed', maul: 'axe_2handed', dagger: 'dagger', spear: 'staff',
    staff: 'staff', wand: 'wand', bow: 'crossbow_2handed', crossbow: 'crossbow_1handed',
};
const K_SHIELDS: Record<string, string> = { buckler: 'shield_round', kite: 'shield_badge', tower: 'shield_square' };

function KProp({ name, parent }: { name: string; parent?: Object3D }) {
    const { scene } = useGLTF(`${K}/weapons/${name}.glb`);
    const prop = useMemo(() => scene.clone(true), [scene]);
    useEffect(() => {
        if (!parent) return;
        parent.add(prop);
        return () => { parent.remove(prop); };
    }, [parent, prop]);
    return null;
}

function KayKitHero({ equipment }: { equipment: Record<string, Gear> }) {
    const main = equipment.weapon;
    const off = equipment.offhand;
    const family = familyOf(main);
    const offFamily = familyOf(off);
    const twoHanded = !!main?.two_handed;
    const character = family === 'staff' || family === 'wand' ? 'Mage'
        : family === 'bow' || family === 'crossbow' || family === 'dagger' ? 'Rogue'
            : family === 'greataxe' || family === 'maul' ? 'Barbarian' : 'Knight';
    const weapon = main ? (family && K_WEAPONS[family]) || (twoHanded ? 'sword_2handed' : 'sword_1handed') : null;
    const offhand = off ? (offFamily && (K_SHIELDS[offFamily] ?? K_WEAPONS[offFamily])) || 'shield_round' : null;

    const group = useRef<Group>(null);
    const { scene, animations } = useGLTF(`${K}/characters/${character}.glb`);
    const model = useMemo(() => {
        const copy = cloneSkinned(scene);
        for (const slot of ['handslot.r', 'handslot.l']) copy.getObjectByName(slot)?.children.forEach((c) => { c.visible = false; });
        return copy;
    }, [scene]);
    const { actions, names } = useAnimations(animations, group);
    useEffect(() => {
        const action = actions[twoHanded && weapon ? '2H_Melee_Idle' : 'Idle'] ?? actions[names[0]];
        action?.reset().fadeIn(0.3).play();
        return () => { action?.fadeOut(0.3); };
    }, [actions, names, twoHanded, weapon]);

    return (
        <group ref={group} scale={0.75}>
            <primitive object={model} />
            {weapon && <KProp name={weapon} parent={model.getObjectByName('handslot.r') ?? undefined} />}
            {offhand && !twoHanded && <KProp name={offhand} parent={model.getObjectByName('handslot.l') ?? undefined} />}
        </group>
    );
}

/* ------------------------------------------------------------------ Viewer ----- */

export default function CharacterModel({ equipment, look }: { equipment: Record<string, Gear>; look?: HeroLook | null }) {
    return (
        <div className="relative h-96 w-full overflow-hidden rounded-xl border border-ink-700 bg-gradient-to-b from-sky-200/40 to-moss-500/20">
            <Canvas camera={{ position: [0, 1.3, 4.4], fov: 32 }} dpr={[1, 2]}>
                <ambientLight intensity={1.1} />
                <directionalLight position={[3, 5, 4]} intensity={1.8} />
                <directionalLight position={[-3, 2, -3]} intensity={0.6} />
                <Suspense fallback={null}>
                    {CHARACTER_SET === 'quaternius' ? <QuaterniusHero equipment={equipment} hero={look} /> : <KayKitHero equipment={equipment} />}
                </Suspense>
                <ContactShadows position={[0, 0, 0]} opacity={0.45} scale={6} blur={2.2} far={3} />
                <OrbitControls target={[0, 1.0, 0]} enablePan={false} minDistance={2} maxDistance={7} minPolarAngle={0.4} maxPolarAngle={1.6} />
            </Canvas>
            <p className="pointer-events-none absolute bottom-2 left-3 text-[11px] text-ink-400">Drag to rotate · scroll to zoom</p>
        </div>
    );
}
