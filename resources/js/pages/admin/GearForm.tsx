import { Head, Link, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { FieldError, PageHeader, Panel } from '@/components/ui';
import { confirmDialog } from '@/lib/dialog';
import { SLOT_LABELS } from '@/lib/format';

interface LootTable {
    id: number;
    code: string;
    name: string;
}

interface ImplicitRow {
    key: string;
    value: number | string;
}

interface Template {
    id: number;
    code: string;
    name: string;
    slot: string;
    item_type: string;
    scaling_stat: string | null;
    two_handed: boolean;
    base_power: number;
    base_armor: number;
    base_mres: number;
    implicit: ImplicitRow[];
    req_level: number;
    base_value: number;
    description: string | null;
    icon: string | null;
    loot: { loot_table_id: number; weight: number }[];
    in_shop: boolean;
    shop_price: number;
    instances: number;
    starter: boolean;
    can_delete: boolean;
}

interface Props {
    template: Template | null;
    slots: string[];
    types_by_slot: Record<string, string[]>;
    stat_keys: string[];
    loot_tables: LootTable[];
}

function emptyLoot(tables: LootTable[]) {
    return tables.map((t) => ({ loot_table_id: t.id, weight: 0 }));
}

export default function GearForm({ template, slots, types_by_slot, stat_keys, loot_tables }: Props) {
    const editing = template !== null;
    const form = useForm({
        code: template?.code ?? '',
        name: template?.name ?? '',
        slot: template?.slot ?? 'weapon',
        item_type: template?.item_type ?? 'sword',
        scaling_stat: template?.scaling_stat ?? 'str',
        two_handed: template?.two_handed ?? false,
        base_power: template?.base_power ?? 0,
        base_armor: template?.base_armor ?? 0,
        base_mres: template?.base_mres ?? 0,
        implicit: (template?.implicit?.length ? template.implicit : [{ key: '', value: '' }]) as ImplicitRow[],
        req_level: template?.req_level ?? 1,
        base_value: template?.base_value ?? 1,
        description: template?.description ?? '',
        icon: template?.icon ?? '',
        loot: template?.loot?.length ? template.loot : emptyLoot(loot_tables),
        in_shop: template?.in_shop ?? false,
    });

    const types = types_by_slot[form.data.slot] ?? [];
    const isWeapon = form.data.slot === 'weapon';

    const setSlot = (slot: string) => {
        const nextTypes = types_by_slot[slot] ?? [];
        form.setData({
            ...form.data,
            slot,
            item_type: nextTypes.includes(form.data.item_type) ? form.data.item_type : (nextTypes[0] ?? ''),
            scaling_stat: slot === 'weapon' ? form.data.scaling_stat || 'str' : '',
            two_handed: slot === 'weapon' ? form.data.two_handed : false,
        });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const implicit = form.data.implicit.filter((row) => row.key && row.value !== '' && row.value !== null);
        const payload = {
            ...form.data,
            scaling_stat: isWeapon ? form.data.scaling_stat : null,
            two_handed: isWeapon ? form.data.two_handed : false,
            implicit,
        };
        form.transform(() => payload);
        if (editing) {
            form.put(`/admin/gear/${template.id}`, { preserveScroll: true });
        } else {
            form.post('/admin/gear');
        }
    };

    const destroy = async () => {
        if (!editing || !template.can_delete) return;
        const ok = await confirmDialog({
            tone: 'danger',
            icon: <Trash2 className="size-8" />,
            title: `Delete ${template.name}?`,
            body: 'This cannot be undone.',
            confirmLabel: 'Delete',
        });
        if (!ok) return;
        form.delete(`/admin/gear/${template.id}`);
    };

    return (
        <>
            <Head title={editing ? `Edit ${template.name}` : 'New gear'} />
            <PageHeader title={editing ? template.name : 'New gear'} subtitle="Templates are the blueprints drops and the market copy. Loot weights decide how often a place can roll this item.">
                <Link href="/admin" className="btn-ghost">All gear</Link>
            </PageHeader>

            <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Panel title="Identity" className="lg:col-span-2">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="label" htmlFor="name">Name</label>
                            <input id="name" className="input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            <FieldError message={form.errors.name} />
                        </div>
                        <div>
                            <label className="label" htmlFor="code">Code</label>
                            <input
                                id="code"
                                className="input font-mono"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="auto from name"
                                disabled={Boolean(template?.starter)}
                            />
                            <FieldError message={form.errors.code} />
                        </div>
                        <div>
                            <label className="label" htmlFor="req_level">Required level</label>
                            <input id="req_level" type="number" min={1} max={30} className="input" value={form.data.req_level} onChange={(e) => form.setData('req_level', Number(e.target.value))} />
                            <FieldError message={form.errors.req_level} />
                        </div>
                        <div>
                            <label className="label" htmlFor="slot">Slot</label>
                            <select id="slot" className="input" value={form.data.slot} onChange={(e) => setSlot(e.target.value)}>
                                {slots.map((s) => (
                                    <option key={s} value={s}>{SLOT_LABELS[s] ?? s}</option>
                                ))}
                            </select>
                            <FieldError message={form.errors.slot} />
                        </div>
                        <div>
                            <label className="label" htmlFor="item_type">Type</label>
                            <select id="item_type" className="input" value={form.data.item_type} onChange={(e) => form.setData('item_type', e.target.value)}>
                                {types.map((t) => (
                                    <option key={t} value={t}>{t}</option>
                                ))}
                            </select>
                            <FieldError message={form.errors.item_type} />
                        </div>
                        {isWeapon && (
                            <>
                                <div>
                                    <label className="label" htmlFor="scaling_stat">Scales with</label>
                                    <select id="scaling_stat" className="input" value={form.data.scaling_stat ?? 'str'} onChange={(e) => form.setData('scaling_stat', e.target.value)}>
                                        <option value="str">Strength</option>
                                        <option value="dex">Dexterity</option>
                                        <option value="int">Intellect (magic damage)</option>
                                    </select>
                                    <FieldError message={form.errors.scaling_stat} />
                                </div>
                                <label className="flex items-end gap-2 pb-2 text-sm text-ink-200">
                                    <input type="checkbox" checked={form.data.two_handed} onChange={(e) => form.setData('two_handed', e.target.checked)} />
                                    Two-handed (clears off-hand)
                                </label>
                            </>
                        )}
                        <div className="sm:col-span-2">
                            <label className="label" htmlFor="description">Description</label>
                            <input id="description" className="input" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} maxLength={255} />
                            <FieldError message={form.errors.description} />
                        </div>
                    </div>
                </Panel>

                <Panel title="Numbers">
                    <div className="space-y-4">
                        <div>
                            <label className="label" htmlFor="base_power">Base power (weapons)</label>
                            <input id="base_power" type="number" min={0} className="input" value={form.data.base_power} onChange={(e) => form.setData('base_power', Number(e.target.value))} />
                        </div>
                        <div>
                            <label className="label" htmlFor="base_armor">Base armor</label>
                            <input id="base_armor" type="number" min={0} className="input" value={form.data.base_armor} onChange={(e) => form.setData('base_armor', Number(e.target.value))} />
                        </div>
                        <div>
                            <label className="label" htmlFor="base_mres">Base magic resist</label>
                            <input id="base_mres" type="number" min={0} className="input" value={form.data.base_mres} onChange={(e) => form.setData('base_mres', Number(e.target.value))} />
                        </div>
                        <div>
                            <label className="label" htmlFor="base_value">Base gold value</label>
                            <input id="base_value" type="number" min={1} className="input" value={form.data.base_value} onChange={(e) => form.setData('base_value', Number(e.target.value))} />
                            <p className="mt-1 text-[11px] text-ink-500">Shop price is computed from this and required level when listed.</p>
                        </div>
                    </div>
                </Panel>

                <Panel title="Implicit mods" className="lg:col-span-2">
                    <p className="mb-3 text-xs text-ink-400">Flat bonuses on every copy of this template (the shared stat-key vocabulary).</p>
                    <div className="space-y-2">
                        {form.data.implicit.map((row, i) => (
                            <div key={i} className="flex gap-2">
                                <select
                                    className="input"
                                    value={row.key}
                                    onChange={(e) => {
                                        const next = [...form.data.implicit];
                                        next[i] = { ...next[i], key: e.target.value };
                                        form.setData('implicit', next);
                                    }}
                                >
                                    <option value="">—</option>
                                    {stat_keys.map((k) => (
                                        <option key={k} value={k}>{k}</option>
                                    ))}
                                </select>
                                <input
                                    type="number"
                                    step="any"
                                    className="input w-28"
                                    value={row.value}
                                    onChange={(e) => {
                                        const next = [...form.data.implicit];
                                        next[i] = { ...next[i], value: e.target.value };
                                        form.setData('implicit', next);
                                    }}
                                />
                                <button
                                    type="button"
                                    className="btn-ghost"
                                    onClick={() => form.setData('implicit', form.data.implicit.filter((_, j) => j !== i))}
                                >
                                    Remove
                                </button>
                            </div>
                        ))}
                    </div>
                    <button type="button" className="btn-ghost mt-3" onClick={() => form.setData('implicit', [...form.data.implicit, { key: '', value: '' }])}>
                        Add mod
                    </button>
                    <FieldError message={form.errors.implicit} />
                </Panel>

                <Panel title="Shop">
                    <label className="flex items-start gap-2 text-sm text-ink-200">
                        <input type="checkbox" className="mt-1" checked={form.data.in_shop} onChange={(e) => form.setData('in_shop', e.target.checked)} />
                        <span>
                            List in the Lanternford market
                            {editing && template.in_shop && (
                                <span className="mt-1 block text-xs text-lantern-300">Current price {template.shop_price}g (recomputed on save)</span>
                            )}
                        </span>
                    </label>
                    {editing && template.instances > 0 && (
                        <p className="mt-3 text-xs text-ink-400">{template.instances} existing item copies keep their rolled power, armor, and magic resist.</p>
                    )}
                </Panel>

                <Panel title="Loot tables" className="lg:col-span-3">
                    <p className="mb-3 text-xs text-ink-400">Weight 0 means this item is not in that table. Higher weight = more often among that table&apos;s rolls.</p>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {loot_tables.map((lt) => {
                            const row = form.data.loot.find((l) => l.loot_table_id === lt.id) ?? { loot_table_id: lt.id, weight: 0 };
                            return (
                                <div key={lt.id} className="rounded-lg border border-ink-700/70 bg-ink-900/50 px-3 py-2">
                                    <div className="text-sm font-semibold text-ink-100">{lt.name}</div>
                                    <div className="mb-1 font-mono text-[11px] text-ink-500">{lt.code}</div>
                                    <input
                                        type="number"
                                        min={0}
                                        className="input"
                                        value={row.weight}
                                        onChange={(e) => {
                                            const weight = Number(e.target.value);
                                            const others = form.data.loot.filter((l) => l.loot_table_id !== lt.id);
                                            form.setData('loot', [...others, { loot_table_id: lt.id, weight }]);
                                        }}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </Panel>

                <div className="flex flex-wrap items-center gap-3 lg:col-span-3">
                    <button className="btn-primary" disabled={form.processing}>{editing ? 'Save gear' : 'Create gear'}</button>
                    {editing && template.can_delete && (
                        <button type="button" className="btn-danger" disabled={form.processing} onClick={destroy}>
                            Delete
                        </button>
                    )}
                    {editing && !template.can_delete && (
                        <span className="text-xs text-ink-400">
                            {template.starter
                                ? 'Starter kit items can be edited but not deleted.'
                                : 'Templates that have dropped or been listed in the shop stay in the database (the gold ledger keeps the listing id).'}
                        </span>
                    )}
                </div>
            </form>
        </>
    );
}
