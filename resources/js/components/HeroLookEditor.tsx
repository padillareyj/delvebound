import clsx from 'clsx';
import { Dices, RotateCcw, Save } from 'lucide-react';
import { Suspense, lazy, useState, type ReactNode } from 'react';
import { RACE_SKIN, defaultLookFor, type Gear } from '@/components/CharacterModel';
import { act } from '@/lib/act';
import type { HeroLook } from '@/types';

const CharacterModel = lazy(() => import('@/components/CharacterModel'));

/*
 * Cosmetic hero look (characters.look). It only changes how the 3D hero is drawn in the preview and the
 * battle replay; stats and the simulation never read it. The server re-validates every field.
 */

const GENDERS: { id: HeroLook['gender']; label: string }[] = [{ id: 'male', label: 'Male' }, { id: 'female', label: 'Female' }];
const RACES: { id: HeroLook['race']; label: string; hint: string }[] = [
    { id: 'human', label: 'Human', hint: 'The classic adventurer.' },
    { id: 'elf', label: 'Elf', hint: 'Taller and slimmer, with pointed ears.' },
    { id: 'dwarf', label: 'Dwarf', hint: 'Short and stocky.' },
    { id: 'orc', label: 'Orc', hint: 'Green skin, broad shoulders, tusks.' },
    { id: 'undead', label: 'Undead', hint: 'Pale and gaunt.' },
];
const OUTFITS: { id: HeroLook['outfit']; label: string }[] = [
    { id: 'peasant', label: 'Peasant' }, { id: 'ranger', label: 'Ranger' }, { id: 'ranger_hooded', label: 'Ranger (hood up)' },
];
const HAIRS: { id: HeroLook['hair']; label: string }[] = [
    { id: 'none', label: 'Bald' }, { id: 'buzzed', label: 'Buzzed' }, { id: 'buzzed_female', label: 'Cropped' },
    { id: 'simple_parted', label: 'Parted' }, { id: 'long', label: 'Long' }, { id: 'buns', label: 'Buns' },
];
const SKINS: { hex: string; label: string }[] = [
    { hex: '#ffffff', label: 'Natural' }, { hex: '#f6eee6', label: 'Moonlit' }, { hex: '#f1c9a5', label: 'Light' },
    { hex: '#d9a47a', label: 'Tan' }, { hex: '#a8704a', label: 'Brown' }, { hex: '#8a5a40', label: 'Deep' },
    { hex: '#9cc47a', label: 'Orc green' }, { hex: '#7aa35a', label: 'Moss' }, { hex: '#c8d0c4', label: 'Pale' }, { hex: '#a9b8b0', label: 'Grave' },
];
const HAIR_COLORS: { hex: string | null; label: string }[] = [
    { hex: null, label: 'Natural' }, { hex: '#231a15', label: 'Black' }, { hex: '#5a3a22', label: 'Brown' }, { hex: '#8a3b1e', label: 'Auburn' },
    { hex: '#a8321e', label: 'Red' }, { hex: '#d8b26a', label: 'Blonde' }, { hex: '#e8e4da', label: 'Platinum' }, { hex: '#9a9a9a', label: 'Grey' },
];

function swatchStyle(hex: string | null) {
    return { background: hex ?? 'linear-gradient(135deg,#8b6a4e,#3a2a1e)' };
}

function Choice<T extends string>({ value, options, onChange }: { value: T; options: { id: T; label: string }[]; onChange: (v: T) => void }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((o) => (
                <button
                    key={o.id}
                    type="button"
                    onClick={() => onChange(o.id)}
                    className={clsx('rounded-lg border px-2.5 py-1 text-xs font-semibold transition',
                        value === o.id ? 'border-lantern-300 bg-lantern-300/15 text-lantern-100' : 'border-ink-700 text-ink-300 hover:border-ink-500')}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-400">{label}</div>
            {children}
        </div>
    );
}

const pickOne = <T,>(list: T[]): T => list[Math.floor(Math.random() * list.length)];

export default function HeroLookEditor({ saved, equipment, rowVersion }: { saved: HeroLook | null; equipment: Record<string, Gear>; rowVersion: number }) {
    const start = saved ?? defaultLookFor(equipment);
    const [look, setLook] = useState<HeroLook>(start);
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<HeroLook>) => setLook((l) => {
        const next = { ...l, ...patch };
        if (next.gender === 'female') next.beard = false;
        return next;
    });
    const changeRace = (race: HeroLook['race']) => set({ race, skin: RACE_SKIN[race], height: 100, build: race === 'orc' ? 108 : 100 });
    const randomize = () => {
        const race = pickOne(RACES).id;
        const gender = pickOne(GENDERS).id;
        const skins = race === 'orc' ? ['#9cc47a', '#7aa35a'] : race === 'undead' ? ['#c8d0c4', '#a9b8b0'] : SKINS.slice(0, 6).map((s) => s.hex);
        set({
            gender, race, skin: pickOne(skins), outfit: pickOne(OUTFITS).id, hair: pickOne(HAIRS).id, hair_color: pickOne(HAIR_COLORS).hex,
            beard: gender === 'male' && Math.random() < 0.5, height: 92 + Math.floor(Math.random() * 17), build: 90 + Math.floor(Math.random() * 26),
        });
    };
    const save = () => {
        setBusy(true);
        act('/character/look', { ...look, beard: look.beard ? 1 : 0, row_version: rowVersion }, { onFinish: () => setBusy(false) });
    };
    const dirty = JSON.stringify(look) !== JSON.stringify(start);
    const race = RACES.find((r) => r.id === look.race);

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
            <Suspense fallback={<div className="h-96 animate-pulse rounded-xl bg-ink-900/40" />}>
                <CharacterModel equipment={equipment} look={look} />
            </Suspense>
            <div className="space-y-4">
                <Row label="Body"><Choice value={look.gender} options={GENDERS} onChange={(gender) => set({ gender })} /></Row>
                <Row label="Race">
                    <Choice value={look.race} options={RACES} onChange={changeRace} />
                    <p className="mt-1 text-xs text-ink-400">{race?.hint} Purely cosmetic: race never changes stats.</p>
                </Row>
                <Row label="Skin">
                    <div className="flex flex-wrap gap-1.5">
                        {SKINS.map((s) => (
                            <button key={s.hex} type="button" title={s.label} onClick={() => set({ skin: s.hex })} style={swatchStyle(s.hex === '#ffffff' ? '#e9c2a6' : s.hex)}
                                className={clsx('size-7 rounded-full border-2', look.skin === s.hex ? 'border-lantern-300' : 'border-ink-700')} />
                        ))}
                    </div>
                </Row>
                <Row label="Outfit"><Choice value={look.outfit} options={OUTFITS} onChange={(outfit) => set({ outfit })} /></Row>
                <Row label="Hair"><Choice value={look.hair} options={HAIRS} onChange={(hair) => set({ hair })} /></Row>
                <Row label="Hair colour">
                    <div className="flex flex-wrap gap-1.5">
                        {HAIR_COLORS.map((c) => (
                            <button key={c.label} type="button" title={c.label} onClick={() => set({ hair_color: c.hex })} style={swatchStyle(c.hex)}
                                className={clsx('size-7 rounded-full border-2', look.hair_color === c.hex ? 'border-lantern-300' : 'border-ink-700')} />
                        ))}
                    </div>
                </Row>
                {look.gender === 'male' && (
                    <label className="flex items-center gap-2 text-sm text-ink-200">
                        <input type="checkbox" checked={look.beard} onChange={(e) => set({ beard: e.target.checked })} /> Beard
                    </label>
                )}
                <div className="grid grid-cols-2 gap-4">
                    <Row label={`Height ${look.height}%`}>
                        <input type="range" min={90} max={110} value={look.height} onChange={(e) => set({ height: Number(e.target.value) })} className="w-full" />
                    </Row>
                    <Row label={`Build ${look.build}%`}>
                        <input type="range" min={85} max={120} value={look.build} onChange={(e) => set({ build: Number(e.target.value) })} className="w-full" />
                    </Row>
                </div>
                <div className="flex flex-wrap gap-2 pt-1">
                    <button type="button" className="btn-ghost" onClick={randomize}><Dices className="size-4" /> Randomize</button>
                    <button type="button" className="btn-ghost" onClick={() => setLook(start)} disabled={!dirty}><RotateCcw className="size-4" /> Undo changes</button>
                    <button type="button" className="btn-primary" onClick={save} disabled={busy || !dirty}><Save className="size-4" /> {busy ? 'Saving…' : 'Save look'}</button>
                </div>
            </div>
        </div>
    );
}
