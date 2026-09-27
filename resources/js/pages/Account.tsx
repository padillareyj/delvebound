import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { LogOut, ShieldCheck } from 'lucide-react';
import { FieldError, PageHeader, Panel } from '@/components/ui';
import { formatDateTime, useTimezone } from '@/lib/format';

interface Props {
    account: { username: string; email: string | null; is_admin: boolean; created_at: string | null; last_login_at: string | null };
}

export default function Account({ account }: Props) {
    const tz = useTimezone();
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/account/password', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <>
            <Head title="Account" />
            <PageHeader title="Account" subtitle={`Signed in as ${account.username}`}>
                <Link href="/logout" method="post" as="button" className="btn-ghost"><LogOut className="size-4" /> Log out</Link>
            </PageHeader>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Panel title="Details">
                    <dl className="divide-y divide-ink-700/50 text-sm">
                        {[
                            ['Username', account.username],
                            ['Email', account.email ?? '—'],
                            ['Joined', formatDateTime(account.created_at, tz)],
                            ['Last login', formatDateTime(account.last_login_at, tz)],
                            ['Times shown in', tz],
                        ].map(([k, v]) => (
                            <div key={k} className="flex justify-between py-2">
                                <dt className="text-ink-400">{k}</dt>
                                <dd className="text-ink-100">{v}</dd>
                            </div>
                        ))}
                    </dl>
                    {account.is_admin && (
                        <p className="mt-4 flex items-center gap-2 text-sm text-lantern-300">
                            <ShieldCheck className="size-4" /> Administrator
                            <Link href="/admin" className="link">Content tools</Link>
                        </p>
                    )}
                </Panel>
                <Panel title="Change password">
                    <form onSubmit={submit} className="space-y-4">
                        {(['current_password', 'password', 'password_confirmation'] as const).map((key) => (
                            <div key={key}>
                                <label className="label" htmlFor={key}>
                                    {key === 'current_password' ? 'Current password' : key === 'password' ? 'New password (10+ characters)' : 'Confirm new password'}
                                </label>
                                <input
                                    id={key}
                                    type="password"
                                    className="input"
                                    autoComplete={key === 'current_password' ? 'current-password' : 'new-password'}
                                    value={form.data[key]}
                                    onChange={(e) => form.setData(key, e.target.value)}
                                />
                                <FieldError message={form.errors[key]} />
                            </div>
                        ))}
                        <button className="btn-primary" disabled={form.processing}>Update password</button>
                    </form>
                </Panel>
            </div>
        </>
    );
}
