import { useFrame, useThree } from '@react-three/fiber';
import { useEffect, useMemo, useRef, type MutableRefObject, type ReactNode } from 'react';
import {
    AdditiveBlending, BufferAttribute, BufferGeometry, CanvasTexture, Group, InstancedMesh, Object3D, PointLight, Points,
    PointsMaterial, RepeatWrapping, SRGBColorSpace, Vector3,
} from 'three';
import type { Cue } from '@/components/CharacterModel';

/*
 * Cheap, procedural set dressing and hit effects for the 3D battle replay. No extra downloads:
 * the ground texture is drawn on a canvas, props are instanced primitives, particles are tiny point clouds.
 */

export type ThemeKey = 'meadow' | 'wilds' | 'salt' | 'ash' | 'spore' | 'boss';
type PropKind = 'trees' | 'pines' | 'reeds' | 'shrooms' | 'pillars';
export interface Theme {
    sky: string; fog: [number, number]; ground: string; ground2: string; hemiSky: string; hemiGround: string; hemi: number;
    sun: string; sunI: number; rim: string; rimI: number; props: PropKind; prop: string; prop2: string; glow?: string; motes?: string;
}

export const THEMES: Record<ThemeKey, Theme> = {
    meadow: { sky: '#cfe6f2', fog: [10, 26], ground: '#86ad62', ground2: '#a3c46f', hemiSky: '#fff8e1', hemiGround: '#4b5d3a', hemi: 1.0, sun: '#fff1d0', sunI: 1.9, rim: '#ffe7b0', rimI: 0.8, props: 'trees', prop: '#5f8a3a', prop2: '#6b4a2e', motes: '#fff6b0' },
    wilds: { sky: '#a9c3b4', fog: [8, 22], ground: '#56703f', ground2: '#3f5a33', hemiSky: '#e6f2dc', hemiGround: '#2c3a24', hemi: 0.9, sun: '#f3e7c4', sunI: 1.6, rim: '#cde8ff', rimI: 1.0, props: 'pines', prop: '#2f5233', prop2: '#4a3524', motes: '#d8ff9a' },
    salt: { sky: '#e9e1cf', fog: [9, 24], ground: '#cbbf9f', ground2: '#e8e2d2', hemiSky: '#fffaf0', hemiGround: '#8a7a5c', hemi: 1.05, sun: '#fff0d6', sunI: 2.0, rim: '#fff4e0', rimI: 0.9, props: 'reeds', prop: '#a89a6a', prop2: '#8f8c84', motes: '#ffffff' },
    ash: { sky: '#5b4a46', fog: [7, 20], ground: '#4a403c', ground2: '#2f2927', hemiSky: '#ffd9b8', hemiGround: '#2a1d18', hemi: 0.8, sun: '#ffb27a', sunI: 1.7, rim: '#ff7a33', rimI: 1.6, props: 'pillars', prop: '#3b3533', prop2: '#2a2524', glow: '#ff5a1a', motes: '#ff8a3a' },
    spore: { sky: '#3d3552', fog: [7, 19], ground: '#4f4a3f', ground2: '#5f5a44', hemiSky: '#d9c8ff', hemiGround: '#2a2433', hemi: 0.9, sun: '#e6d6ff', sunI: 1.3, rim: '#9affd6', rimI: 1.3, props: 'shrooms', prop: '#e9e1d0', prop2: '#b04a6a', glow: '#7affd0', motes: '#b8ffe6' },
    boss: { sky: '#3a2226', fog: [8, 20], ground: '#5a3c36', ground2: '#3e2824', hemiSky: '#ffd0b0', hemiGround: '#2a1614', hemi: 1.05, sun: '#ffd2a8', sunI: 2.0, rim: '#ff9a4a', rimI: 3.2, props: 'pillars', prop: '#4a3532', prop2: '#33241f', glow: '#ff4a1a', motes: '#ff7a2a' },
};

/** Picks a look from the place code/name (regions aren't sent to the page; the place names carry them). */
export function themeFor(place?: { code?: string; name?: string; type?: string }, boss?: boolean): ThemeKey {
    if (boss) return 'boss';
    const s = `${place?.code ?? ''} ${place?.name ?? ''} ${place?.type ?? ''}`.toLowerCase();
    if (/ash|cinder|ember|citadel|crown|stair|obsidian|forge|magma|kiln/.test(s)) return 'ash';
    if (/mycel|spore|mushroom|fung/.test(s)) return 'spore';
    if (/brine|salt|tide|qanat|dune|mirage|gen5|desert|sand|marsh|fen|bog|mire|coast/.test(s)) return 'salt';
    if (/forest|wood|wild|frost|fang|cave|mine|hollow|grove|ruin/.test(s)) return 'wilds';
    return 'meadow';
}

function rng(seed: number) {
    let s = seed;
    return () => ((s = (s * 16807) % 2147483647) / 2147483647);
}

export function Ground({ theme }: { theme: Theme }) {
    const tex = useMemo(() => {
        const c = document.createElement('canvas');
        c.width = c.height = 256;
        const g = c.getContext('2d')!;
        g.fillStyle = theme.ground;
        g.fillRect(0, 0, 256, 256);
        const r = rng(11);
        for (let i = 0; i < 700; i++) {
            const x = r() * 256, y = r() * 256, rad = 2 + r() * 14;
            g.globalAlpha = 0.04 + r() * 0.1;
            g.fillStyle = r() < 0.55 ? theme.ground2 : r() < 0.5 ? '#000000' : '#ffffff';
            g.beginPath();
            g.ellipse(x, y, rad, rad * (0.4 + r() * 0.6), r() * 3, 0, Math.PI * 2);
            g.fill();
        }
        const t = new CanvasTexture(c);
        t.wrapS = t.wrapT = RepeatWrapping;
        t.repeat.set(16, 16);
        t.colorSpace = SRGBColorSpace;
        t.anisotropy = 4;
        return t;
    }, [theme]);
    useEffect(() => () => tex.dispose(), [tex]);
    return (
        <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, -0.01, 0]}>
            <circleGeometry args={[40, 64]} />
            <meshStandardMaterial map={tex} roughness={0.95} />
        </mesh>
    );
}

type Item = { x: number; z: number; s: number; r: number };

function Instanced({ items, y, color, emissive, children }: { items: Item[]; y: number; color: string; emissive?: string; children: ReactNode }) {
    const ref = useRef<InstancedMesh>(null);
    useEffect(() => {
        const m = ref.current;
        if (!m) return;
        const o = new Object3D();
        items.forEach((it, i) => {
            o.position.set(it.x, y * it.s, it.z);
            o.rotation.set(0, it.r, 0);
            o.scale.setScalar(it.s);
            o.updateMatrix();
            m.setMatrixAt(i, o.matrix);
        });
        m.instanceMatrix.needsUpdate = true;
    }, [items, y]);
    return (
        <instancedMesh ref={ref} args={[undefined, undefined, items.length]} frustumCulled={false}>
            {children}
            <meshStandardMaterial color={color} flatShading emissive={emissive ?? '#000000'} emissiveIntensity={emissive ? 0.7 : 0} />
        </instancedMesh>
    );
}

/** A ring of props behind and beside the fight; the camera side stays open. */
export function Props({ theme }: { theme: Theme }) {
    const items = useMemo(() => {
        const r = rng(theme.props.length * 97 + 5);
        const out: Item[] = [];
        while (out.length < 26) {
            const a = Math.PI * (0.92 + r() * 1.16); // behind: angles ~ 165°..375°
            const d = 7.5 + r() * 8;
            const x = Math.cos(a) * d * 1.3, z = Math.sin(a) * d * 0.9 - 1.5;
            if (z > -2.2 && Math.abs(x) < 6) continue;
            out.push({ x, z, s: 0.7 + r() * 0.8, r: r() * 6.28 });
        }
        return out;
    }, [theme]);
    const rocks = useMemo(() => items.filter((_, i) => i % 3 === 0).map((it) => ({ ...it, x: it.x * 0.7 + 1, z: it.z * 0.6, s: it.s * 0.6 })), [items]);
    const p = theme.props;
    return (
        <group>
            {p === 'trees' && <>
                <Instanced items={items} y={0.45} color={theme.prop2}><cylinderGeometry args={[0.12, 0.17, 0.9, 6]} /></Instanced>
                <Instanced items={items} y={1.55} color={theme.prop}><icosahedronGeometry args={[0.85, 0]} /></Instanced>
            </>}
            {p === 'pines' && <>
                <Instanced items={items} y={0.35} color={theme.prop2}><cylinderGeometry args={[0.1, 0.15, 0.7, 6]} /></Instanced>
                <Instanced items={items} y={1.6} color={theme.prop}><coneGeometry args={[0.7, 2.2, 7]} /></Instanced>
            </>}
            {p === 'reeds' && <Instanced items={items} y={0.5} color={theme.prop}><coneGeometry args={[0.18, 1.0, 4]} /></Instanced>}
            {p === 'shrooms' && <>
                <Instanced items={items} y={0.5} color={theme.prop}><cylinderGeometry args={[0.1, 0.15, 1.0, 6]} /></Instanced>
                <Instanced items={items} y={0.95} color={theme.prop2} emissive={theme.glow}><sphereGeometry args={[0.6, 10, 6, 0, Math.PI * 2, 0, Math.PI / 2]} /></Instanced>
            </>}
            {p === 'pillars' && <Instanced items={items} y={1.1} color={theme.prop}><boxGeometry args={[0.55, 2.2, 0.55]} /></Instanced>}
            <Instanced items={rocks} y={0.12} color={theme.prop2} emissive={p === 'pillars' ? theme.glow : undefined}><dodecahedronGeometry args={[0.45, 0]} /></Instanced>
        </group>
    );
}

/** Drifting motes: fireflies, dust, embers or spores depending on the theme. */
export function Motes({ color, rise }: { color: string; rise: number }) {
    const N = 70;
    const { geo, seed } = useMemo(() => {
        const g = new BufferGeometry();
        const p = new Float32Array(N * 3);
        const r = rng(3);
        const seed = new Float32Array(N);
        for (let i = 0; i < N; i++) { p.set([(r() - 0.5) * 16, r() * 4, -r() * 8 + 2], i * 3); seed[i] = r() * 6.28; }
        g.setAttribute('position', new BufferAttribute(p, 3));
        return { geo: g, seed };
    }, []);
    const mat = useMemo(() => new PointsMaterial({ size: 0.05, color, transparent: true, opacity: 0.8, depthWrite: false, blending: AdditiveBlending }), [color]);
    useFrame(({ clock }, dt) => {
        const p = geo.attributes.position.array as Float32Array;
        const t = clock.elapsedTime;
        for (let i = 0; i < N; i++) {
            p[i * 3] += Math.sin(t * 0.6 + seed[i]) * dt * 0.15;
            p[i * 3 + 1] += rise * dt;
            if (p[i * 3 + 1] > 4) p[i * 3 + 1] = 0;
        }
        geo.attributes.position.needsUpdate = true;
    });
    return <points geometry={geo} material={mat} frustumCulled={false} />;
}

/** Step toward the target on an attack (and flinch back on a hit), then return. dir is +1 / -1 in world x. */
export function Lunge({ cue, dir, dist, speed, children }: { cue?: Cue; dir: number; dist: number; speed: number; children: ReactNode }) {
    const g = useRef<Group>(null);
    const st = useRef({ t: 9, kind: '', key: cue ? `${cue.kind}${cue.n}` : '' });
    useEffect(() => {
        if (!cue || cue.kind === 'death') return;
        const key = `${cue.kind}${cue.n}`;
        if (key === st.current.key) return;
        st.current = { t: 0, kind: cue.kind, key };
    }, [cue]);
    useFrame((_, dt) => {
        const s = st.current;
        if (!g.current) return;
        s.t += dt * speed;
        const T = s.kind === 'attack' ? 0.75 : 0.3;
        const k = s.t < T ? Math.sin((Math.PI * s.t) / T) : 0;
        g.current.position.x = dir * (s.kind === 'attack' ? dist : -0.2) * k;
    });
    return <group ref={g}>{children}</group>;
}

/** Sparks and a short light flash when this fighter takes damage; a bigger gold burst on a crit. */
export function HitBurst({ hits, y }: { hits: { id: number; kind: string }[]; y: number }) {
    const N = 48;
    const pts = useRef<Points>(null);
    const light = useRef<PointLight>(null);
    const latest = hits[hits.length - 1];
    const st = useRef({ t: 9, n: 0, crit: false, last: latest?.id ?? -1 });
    const geo = useMemo(() => {
        const g = new BufferGeometry();
        g.setAttribute('position', new BufferAttribute(new Float32Array(N * 3), 3));
        return g;
    }, []);
    const vel = useMemo(() => new Float32Array(N * 3), []);
    const mat = useMemo(() => new PointsMaterial({ size: 0.09, color: '#ffb070', transparent: true, depthWrite: false, blending: AdditiveBlending }), []);
    useEffect(() => {
        if (!latest || latest.id === st.current.last) return;
        const crit = latest.kind === 'crit';
        const n = crit ? N : 18;
        st.current = { t: 0, n, crit, last: latest.id };
        const pos = geo.attributes.position.array as Float32Array;
        for (let i = 0; i < N; i++) {
            pos.set([0, y, 0], i * 3);
            const a = Math.random() * Math.PI * 2, e = (Math.random() - 0.2) * 1.2, s = (crit ? 3.4 : 2.1) * (0.4 + Math.random());
            vel.set([Math.cos(a) * Math.cos(e) * s, Math.sin(e) * s + 1.2, Math.sin(a) * Math.cos(e) * s * 0.6], i * 3);
        }
        geo.setDrawRange(0, n);
        geo.attributes.position.needsUpdate = true;
        mat.color.set(crit ? '#ffd34a' : '#ffb070');
        mat.size = crit ? 0.15 : 0.08;
        if (light.current) light.current.color.set(crit ? '#ffcf5a' : '#ff9a5a');
    }, [latest, geo, vel, mat, y]);
    useFrame((_, dt) => {
        const s = st.current;
        if (!pts.current || !light.current) return;
        if (s.t > 1.1) { pts.current.visible = false; light.current.intensity = 0; return; }
        s.t += dt;
        pts.current.visible = true;
        const pos = geo.attributes.position.array as Float32Array;
        for (let i = 0; i < s.n; i++) {
            vel[i * 3 + 1] -= 7 * dt;
            pos[i * 3] += vel[i * 3] * dt;
            pos[i * 3 + 1] = Math.max(0.02, pos[i * 3 + 1] + vel[i * 3 + 1] * dt);
            pos[i * 3 + 2] += vel[i * 3 + 2] * dt;
        }
        geo.attributes.position.needsUpdate = true;
        mat.opacity = Math.max(0, 1 - s.t / 0.9);
        light.current.intensity = Math.max(0, (s.crit ? 18 : 7) * (1 - s.t / 0.3));
    });
    return (
        <group>
            <points ref={pts} geometry={geo} material={mat} frustumCulled={false} visible={false} />
            <pointLight ref={light} position={[0, y, 0.7]} intensity={0} distance={4.5} decay={1.5} />
        </group>
    );
}

/** Eases the view a little toward whoever is acting and shakes on crits. Works with OrbitControls (makeDefault). */
export function CameraRig({ focus, shakeKey }: { focus: MutableRefObject<number>; shakeKey?: number }) {
    const controls = useThree((s) => s.controls) as unknown as { target: Vector3 } | null;
    const camera = useThree((s) => s.camera);
    const st = useRef({ pan: 0, shake: 9, off: new Vector3(), key: shakeKey });
    useEffect(() => {
        if (shakeKey !== undefined && shakeKey !== st.current.key) { st.current.key = shakeKey; st.current.shake = 0; }
    }, [shakeKey]);
    useFrame((_, dt) => {
        const s = st.current;
        camera.position.sub(s.off);
        s.off.set(0, 0, 0);
        const d = (focus.current * 0.22 - s.pan) * Math.min(1, dt * 1.6);
        s.pan += d;
        camera.position.x += d;
        if (controls?.target) controls.target.x += d;
        if (s.shake < 0.45) {
            s.shake += dt;
            const a = 0.14 * (1 - s.shake / 0.45);
            s.off.set((Math.random() - 0.5) * a, (Math.random() - 0.5) * a, 0);
            camera.position.add(s.off);
        }
    });
    return null;
}
