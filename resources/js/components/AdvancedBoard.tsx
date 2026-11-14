import clsx from 'clsx';
import { Panel } from '@/components/ui';
import {
    ADVANCED_ELEMENT_LABELS, ADVANCED_ELEMENT_ORDER, ADVANCED_GLOBAL_LABELS, ADVANCED_GLOBAL_ORDER,
    ELEMENT_META, ELEMENTS, advancedValue, derivedValue,
} from '@/lib/format';
import type { AdvancedSheet, Derived } from '@/types';

/** Hero → Detail: the mobile-style combat sheet. Values start at 0 until later systems raise them. */
export default function AdvancedBoard({ sheet, derived }: { sheet: AdvancedSheet; derived: Derived }) {
    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <Panel title="Core combat">
                <p className="mb-3 text-xs text-ink-400">
                    These already work in trips and the arena. The detail sheet below is ready for future systems.
                </p>
                <StatList
                    rows={[
                        { label: 'Lifesteal on Hit', value: derivedValue('lifesteal', derived.lifesteal ?? 0) },
                        { label: 'Armor Pierce', value: derivedValue('penetration', derived.penetration ?? 0) },
                        { label: 'Damage Reduction', value: derivedValue('dmg_reduction', derived.dmg_reduction ?? 0) },
                        { label: 'XP Gain', value: derivedValue('xp_mult', derived.xp_mult ?? 1) },
                    ]}
                />
            </Panel>

            <Panel title="Combat detail">
                <StatList
                    rows={ADVANCED_GLOBAL_ORDER.map((key) => ({
                        label: ADVANCED_GLOBAL_LABELS[key],
                        value: advancedValue(key, sheet.global[key] ?? 0),
                        pct: key.endsWith('_pct'),
                    }))}
                />
            </Panel>

            {ELEMENTS.map((element) => {
                const meta = ELEMENT_META[element];
                const row = sheet.elements[element];
                return (
                    <Panel
                        key={element}
                        title={<span className={clsx('inline-flex items-center gap-2', meta.tone)}><span aria-hidden>{meta.glyph}</span> {meta.label}</span>}
                    >
                        <StatList
                            rows={ADVANCED_ELEMENT_ORDER.map((key) => ({
                                label: `${meta.label} ${ADVANCED_ELEMENT_LABELS[key]}`,
                                value: advancedValue(key, Number((row as unknown as Record<string, number>)[key] ?? 0), row),
                                glyph: meta.glyph,
                                tone: meta.tone,
                            }))}
                        />
                    </Panel>
                );
            })}
        </div>
    );
}

function StatList({ rows }: { rows: { label: string; value: string; glyph?: string; tone?: string; pct?: boolean }[] }) {
    return (
        <ul className="divide-y divide-ink-700/40">
            {rows.map((row) => (
                <li key={row.label} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                    <span className="flex min-w-0 items-center gap-2 text-ink-300">
                        {row.glyph && <span className={clsx('shrink-0 text-xs', row.tone)} aria-hidden>{row.glyph}</span>}
                        <span className="truncate">{row.label}</span>
                    </span>
                    <span className="shrink-0 font-semibold tabular-nums text-ink-100">{row.value}</span>
                </li>
            ))}
        </ul>
    );
}
