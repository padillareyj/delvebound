import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FieldError } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function Login({ allowSignup }: { allowSignup: boolean }) {
    const form = useForm({ username: '', password: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title="Delvebound" subtitle="The lanterns of Lanternford are lit. Your expedition awaits.">
            <Head title="Sign in" />
            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label className="label" htmlFor="username">Username</label>
                    <input
                        id="username"
                        className="input"
                        autoComplete="username"
                        autoFocus
                        value={form.data.username}
                        onChange={(e) => form.setData('username', e.target.value)}
                    />
                    <FieldError message={form.errors.username} />
                </div>
                <div>
                    <label className="label" htmlFor="password">Password</label>
                    <input
                        id="password"
                        type="password"
                        className="input"
                        autoComplete="current-password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                    <FieldError message={form.errors.password} />
                </div>
                <button className="btn-primary w-full py-2.5" disabled={form.processing}>
                    {form.processing ? 'Opening the gates…' : 'Enter Lanternford'}
                </button>
            </form>
            {allowSignup && (
                <p className="mt-6 text-center text-sm text-ink-400">
                    New to the realm?{' '}
                    <Link href="/register" className="link">Create a hero</Link>
                </p>
            )}
        </AuthLayout>
    );
}
