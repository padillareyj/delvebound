import { Head, Link, useForm } from '@inertiajs/react';
import clsx from 'clsx';
import type { FormEvent } from 'react';
import { FieldError } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';
import { STAT_NAMES } from '@/lib/format';
import type { StatKey } from '@/types';

interface Weapon {
    code: string;
    name: string;
    stat: StatKey;
}

const WEAPON_FLAVOR: Record<string, { glyph: string; style: string }> = {
    str: { glyph: '🗡️', style: 'Hit hard, stand firm.' },
    dex: { glyph: '🏹', style: 'Strike first, crit often, dodge.' },
    int: { glyph: '🪄', style: 'Magic damage: armor is useless, only magic resistance helps.' },
};

export default function Register({ weapons }: { weapons: Weapon[] }) {
    const form = useForm({
        username: '',
        email: '',
        password: '',
        password_confirmation: '',
        character_name: '',
        weapon: weapons[0]?.code ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    const field = (key: 'username' | 'email' | 'password' | 'password_confirmation' | 'character_name', label: string, props: Record<string, unknown> = {}) => (
        <div>
            <label className="label" htmlFor={key}>{label}</label>
            <input id={key} className="input" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} {...props} />
            <FieldError message={form.errors[key]} />
        </div>
    );

    return (
        <AuthLayout title="New Hero" subtitle="One account, one hero. Choose a name the bards can pronounce.">
            <Head title="Create a hero" />
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    {field('username', 'Username', { autoComplete: 'username', autoFocus: true })}
                    {field('email', 'Email (optional)', { type: 'email', autoComplete: 'email' })}
                    {field('password', 'Password', { type: 'password', autoComplete: 'new-password' })}
                    {field('password_confirmation', 'Confirm password', { type: 'password', autoComplete: 'new-password' })}
                </div>
                {field('character_name', 'Hero name', { maxLength: 32 })}
                <div>
                    <span className="label">Starting weapon</span>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {weapons.map((w) => (
                            <button
                                type="button"
                                key={w.code}
                                onClick={() => form.setData('weapon', w.code)}
                                className={clsx(
                                    'rounded-xl border p-3 text-left transition',
                                    form.data.weapon === w.code
                                        ? 'border-lantern-400 bg-lantern-400/10 shadow-[0_0_20px_-8px_var(--color-lantern-400)]'
                                        : 'border-ink-600 bg-ink-900/50 hover:border-ink-500',
                                )}
                            >
                                <div className="text-2xl">{WEAPON_FLAVOR[w.stat]?.glyph ?? '⚔️'}</div>
                                <div className="mt-1 text-sm font-semibold text-ink-100">{w.name}</div>
                                <div className="text-[11px] text-lantern-300">{STAT_NAMES[w.stat]?.name}</div>
                                <div className="mt-1 text-[11px] leading-snug text-ink-400">{WEAPON_FLAVOR[w.stat]?.style}</div>
                            </button>
                        ))}
                    </div>
                    <FieldError message={form.errors.weapon} />
                </div>
                <button className="btn-primary w-full py-2.5" disabled={form.processing}>
                    {form.processing ? 'Forging your legend…' : 'Begin the adventure'}
                </button>
            </form>
            <p className="mt-6 text-center text-sm text-ink-400">
                Already have a hero? <Link href="/login" className="link">Sign in</Link>
            </p>
        </AuthLayout>
    );
}
