import { Head, Link } from '@inertiajs/react';
import { Plus, Terminal } from 'lucide-react';
import { Empty, PageHeader, Panel } from '@/components/ui';
import { SLOT_LABELS, STAT_NAMES, modLine } from '@/lib/format';

interface LootRef {
    code: string;
    weight: number;
}

interface GearRow {
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
    implicit: Record<string, number>;
    req_level: number;
    base_value: number;
    shop_price: number | null;
    loot: LootRef[];
    instances: number;
    starter: boolean;
}

export default function AdminIndex({ gear }: { gear: GearRow[] }) {
    return (
        <>
            <Head title="Admin · Gear" />
            <PageHeader title="Gear templates" subtitle="These rows live in item_templates. New drops and shop copies use them on the next expedition; already-dropped items keep their rolled power and armor.">
                <Link href="/runs" className="btn-ghost">
                    <Terminal className="size-4" /> Runs
                </Link>
                <Link href="/admin/gear/create" className="btn-primary">
                    <Plus className="size-4" /> New gear
                </Link>
            </PageHeader>
            <Panel bodyClass="p-0">
                {gear.length === 0 ? (
                    <Empty icon="🗡️" title="No templates yet" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-[11px] uppercase tracking-wider text-ink-400">
                                <tr className="border-b border-ink-700/60">
                                    <th className="px-4 py-3">Item</th>
                                    <th className="px-4 py-3">Slot</th>
                                    <th className="px-4 py-3">Req</th>
                                    <th className="px-4 py-3">Power / Armor / MRes</th>
                                    <th className="px-4 py-3">Implicit</th>
                                    <th className="px-4 py-3">Drops / Shop</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink-800">
                                {gear.map((g) => (
                                    <tr key={g.id} className="align-top hover:bg-ink-800/40">
                                        <td className="px-4 py-3">
                                            <Link href={`/admin/gear/${g.id}/edit`} className="font-display font-bold text-lantern-200 hover:text-lantern-100">
                                                {g.name}
                                            </Link>
                                            <div className="font-mono text-[11px] text-ink-500">{g.code}</div>
                                            {g.starter && <div className="mt-1 text-[11px] text-lantern-300">starter kit</div>}
                                        </td>
                                        <td className="px-4 py-3 text-ink-300">
                                            {SLOT_LABELS[g.slot] ?? g.slot}
                                            <div className="text-[11px] text-ink-500">
                                                {g.item_type}
                                                {g.scaling_stat && ` · ${STAT_NAMES[g.scaling_stat as keyof typeof STAT_NAMES]?.short ?? g.scaling_stat}`}
                                                {g.two_handed && ' · 2H'}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 tabular-nums text-ink-300">{g.req_level}</td>
                                        <td className="px-4 py-3 tabular-nums text-ink-300">
                                            {g.base_power} / {g.base_armor} / {g.base_mres}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-ink-300">
                                            {Object.keys(g.implicit).length === 0
                                                ? '—'
                                                : Object.entries(g.implicit).map(([k, v]) => (
                                                      <div key={k}>{modLine(k, Number(v))}</div>
                                                  ))}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-ink-300">
                                            {g.loot.length === 0 ? <div className="text-ink-500">no loot tables</div> : g.loot.map((l) => (
                                                <div key={l.code}>{l.code} · w{l.weight}</div>
                                            ))}
                                            {g.shop_price != null && <div className="text-lantern-300">shop {g.shop_price}g</div>}
                                            {g.instances > 0 && <div className="text-ink-500">{g.instances} in world</div>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </>
    );
}
