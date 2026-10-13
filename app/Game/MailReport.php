<?php

namespace App\Game;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Render a trip result into [subject, body, payload] (DESIGN.md §9). */
final class MailReport
{
    public const OUTCOME_LABEL = [
        'cleared' => 'CLEARED',
        'retreated' => 'RETREATED',
        'defeated' => 'DEFEATED',
        'rested' => 'RESTED',
        'blocked_injured' => 'FORCED REST',
        'order_rejected' => 'ORDER REJECTED',
    ];

    /** @return array{0: string, 1: string, 2: array} */
    public static function render(array $result, int $runId, DateTimeInterface|string $when, int $tripNo, string $displayTz = 'Asia/Bangkok'): array
    {
        $place = $result['place'];
        $outcome = $result['outcome'];
        $label = self::OUTCOME_LABEL[$outcome] ?? strtoupper($outcome);
        $subject = "[Run #{$runId}] {$place['name']} — {$label}";
        if ($result['level_after'] !== $result['level_before']) {
            $subject .= " (Lv {$result['level_before']} → {$result['level_after']}!)";
        }
        $subject = mb_substr($subject, 0, 160);
        $stamp = self::stamp($when, $displayTz);

        $body = match (true) {
            $outcome === 'order_rejected' => self::rejectedBody($result, $runId, $stamp),
            in_array($outcome, ['rested', 'blocked_injured'], true) => self::restBody($result, $runId, $stamp),
            default => self::combatBody($result, $runId, $stamp),
        };

        return [$subject, $body, self::payload($result, $runId, $tripNo)];
    }

    private static function line(string $char = '═'): string
    {
        return str_repeat($char, 59);
    }

    private static function stamp(DateTimeInterface|string $when, string $tz): string
    {
        $utc = new DateTimeZone('UTC');
        $at = is_string($when) ? new DateTimeImmutable($when, $utc) : DateTimeImmutable::createFromInterface($when);

        return $at->setTimezone(new DateTimeZone($tz))->format('d M Y H:i');
    }

    private static function header(int $runId, string $stamp): string
    {
        return "  DELVEBOUND · EXPEDITION REPORT · Run #{$runId} · {$stamp}";
    }

    private static function rejectedBody(array $result, int $runId, string $stamp): string
    {
        $place = $result['place'];
        $level = $result['level_before'];
        $why = match ($result['reject_reason'] ?? null) {
            'too_high' => "You are level {$level}; {$place['name']} is capped at Lv {$place['max_level']}",
            'too_low' => "You are level {$level}; {$place['name']} requires Lv {$place['min_level']}",
            default => "You are level {$level}; {$place['name']} is closed to you",
        };
        $open = implode(', ', $result['open_places'] ?? []) ?: 'Town';
        $lines = [
            self::line(),
            self::header($runId, $stamp),
            "  Destination: {$place['name']}   Outcome: ORDER REJECTED",
            self::line(),
            "{$why}, so you stayed in Lanternford and rested (+{$result['gold_gained']} g).",
            ! empty($result['order_id']) ? 'Your order was cancelled.' : 'Your auto-repeat was cancelled.',
            "Places open to you: {$open}.",
            self::line(),
        ];

        return implode("\n", $lines)."\n";
    }

    private static function restBody(array $result, int $runId, string $stamp): string
    {
        $place = $result['place'];
        $blocked = $result['outcome'] === 'blocked_injured';
        $detail = $blocked
            ? 'You are badly wounded and cannot travel. You rest in Lanternford until the injury passes.'
            : "You spent the run in {$place['name']}. Odd jobs paid {$result['gold_gained']} gold.";
        $lines = [
            self::line(),
            self::header($runId, $stamp),
            "  Destination: {$place['name']}   Outcome: ".self::OUTCOME_LABEL[$result['outcome']],
            self::line(),
            $detail,
            ($blocked || $result['injury']['severity'] === 0) ? 'Status: healthy. The injury has cleared.' : 'Status updated.',
            self::line(),
        ];

        return implode("\n", $lines)."\n";
    }

    private static function combatBody(array $result, int $runId, string $stamp): string
    {
        $place = $result['place'];
        $stats = $result['stats'] ?? [];
        $buffs = $result['buffs'] ?? [];
        $buffNote = match (true) {
            in_array('stoneskin', $buffs, true) => ' (Stoneskin Tonic)',
            in_array('might', $buffs, true) => ' (Elixir of Might)',
            in_array('luck', $buffs, true) => ' (Lucky Charm)',
            default => '',
        };
        $dtype = ($stats['damage_type'] ?? null) === 'magic' ? 'magic' : 'phys';
        $lines = [
            self::line(),
            self::header($runId, $stamp),
            "  Destination: {$place['name']} ({$place['place_type']}, Lv {$place['rec_min_level']}–{$place['rec_max_level']})"
                .'   Outcome: '.self::OUTCOME_LABEL[$result['outcome']],
            self::line(),
            "Starting HP {$result['max_hp']}/{$result['max_hp']} · Attack ".($stats['attack'] ?? '?')." ({$dtype})"
                .' · Def '.($stats['defense'] ?? '?').$buffNote,
        ];
        if (! empty($result['uses'])) {
            $used = implode(', ', array_map(fn ($row) => str_replace('_', ' ', $row['code'])." ×{$row['qty']}", $result['uses']));
            $lines[] = "Used: {$used}";
        }
        $lines[] = '';
        foreach ($result['encounters'] as $enc) {
            $names = implode(', ', $enc['names'] ?? []) ?: 'the road ahead';
            $title = $enc['is_boss'] ? "#{$enc['seq']}  BOSS: {$names}" : "#{$enc['seq']}  {$names}";
            $lines[] = "{$title}  ".strtoupper(str_replace('_', ' ', $enc['result']))." in {$enc['rounds']} rounds   HP {$enc['hp_before']} → {$enc['hp_after']}";
            if ($enc['xp'] || $enc['gold']) {
                $lines[] = "    +{$enc['xp']} XP · +{$enc['gold']} g · dealt {$enc['damage_dealt']} · took {$enc['damage_taken']}";
            }
            foreach ($enc['drops'] ?? [] as $drop) {
                $lines[] = '    ► Loot: '.self::itemLine($drop);
            }
            foreach ($enc['materials'] ?? [] as $mat) {
                $lines[] = "    ► Material: {$mat['name']} ×{$mat['qty']}";
            }
        }
        if ($result['outcome'] === 'cleared' && ! empty($result['clear_bonus'])) {
            $lines[] = "Clear bonus: +{$result['clear_bonus']} XP (20%)";
        }
        if (! empty($result['loot_box'])) {
            $rolls = (int) $result['loot_box']['rolls'];
            $lines[] = "Sealed chest: {$rolls} unopened ".($rolls === 1 ? 'roll' : 'rolls').' waiting in your inventory. Open it when you are ready.';
        }
        $lines[] = self::line('─');
        $lines[] = "TOTALS   XP +{$result['xp_gained']} · Gold +{$result['gold_gained']} · Items ".count($result['items'])." · Kills {$result['kills']}"
            .($result['boss_kills'] ? " ({$result['boss_kills']} boss)" : '');
        foreach ($result['levels'] as $step) {
            $lines[] = "LEVEL UP! → {$step['level']}   +{$step['points']} stat points · +{$step['gold']} gold";
        }
        if ($result['outcome'] === 'defeated') {
            $lines[] = 'Defeat: XP and gold halved. Everything found on this trip was lost.';
            foreach ($result['lost_items'] as $drop) {
                $lines[] = '    Lost: '.self::itemLine($drop);
            }
            foreach ($result['lost_materials'] ?? [] as $mat) {
                $lines[] = "    Lost: {$mat['name']} ×{$mat['qty']}";
            }
        }
        foreach ($result['autosell_owned'] ?? [] as $sold) {
            $lines[] = "Pack full: sold {$sold['name']} for {$sold['sell_value']} g.";
        }
        foreach ($result['autosell_new'] ?? [] as $sold) {
            $lines[] = 'Pack full: sold '.self::itemLine($sold).' on the spot.';
        }
        $injury = $result['injury'];
        if ($result['outcome'] === 'defeated' && $injury['severity'] === 1) {
            $lines[] = 'You are Wounded: −20% HP and −10% attack on your next trip. Rest in Lanternford or use a Bandage Kit.';
        } elseif ($result['outcome'] === 'defeated' && $injury['severity'] === 2) {
            $lines[] = 'You are Badly wounded. Your next run will be a forced rest in Lanternford.';
        } elseif ($injury['severity'] === 0) {
            $lines[] = 'Status: healthy.';
        }
        $lines[] = self::line();

        return implode("\n", $lines)."\n";
    }

    private static function itemLine(array $item): string
    {
        return "{$item['display_name']} [{$item['rarity']}, ilvl {$item['item_level']}]";
    }

    private static function payload(array $result, int $runId, int $tripNo): array
    {
        $place = $result['place'];
        $maxHp = $result['max_hp'] ?: 1;
        $bossCode = null;
        foreach ($result['encounters'] as $enc) {
            if (! empty($enc['is_boss']) && ! empty($enc['monsters'])) {
                $bossCode = $enc['monsters'][0]['code'] ?? $enc['monsters'][0]['name'];
            }
        }
        $brief = fn ($item) => [
            'rarity' => $item['rarity'],
            'name' => $item['display_name'],
            'slot' => $item['slot'] ?? $item['template_slot'] ?? null,
            'item_type' => $item['item_type'] ?? null,
            'two_handed' => (bool) ($item['two_handed'] ?? false),
            'code' => $item['code'] ?? null,
            'icon' => ItemArt::icon($item),
        ];

        return [
            'v' => 1,
            'run_id' => $runId,
            'trip_no' => $tripNo,
            'place_id' => $place['id'] ?? null,
            'place_code' => $place['code'] ?? null,
            'place_rec_level' => $place['rec_level'] ?? null,
            'place_rec_range' => [$place['rec_min_level'] ?? null, $place['rec_max_level'] ?? null],
            'outcome' => $result['outcome'],
            'encounters' => count(array_filter($result['encounters'], fn ($enc) => $enc['result'] !== 'left_early')),
            'encounters_won' => $result['encounters_won'],
            'boss' => ['met' => $result['boss_encountered'], 'killed' => $result['boss_killed'], 'code' => $bossCode],
            'hp_start' => $result['max_hp'],
            'hp_end' => $result['hp_end'],
            'hp_end_pct' => (int) round(100 * $result['hp_end'] / $maxHp),
            'xp' => $result['xp_gained'],
            'gold' => $result['gold_gained'],
            'level_before' => $result['level_before'],
            'level_after' => $result['level_after'],
            'items' => array_map($brief, $result['items']),
            'loot_box' => $result['loot_box'] ?? null,
            'lost_items' => array_map($brief, $result['lost_items']),
            'injury' => $result['injury'],
            'consumables_used' => array_map(fn ($row) => ['code' => $row['code'], 'qty' => $row['qty']], $result['uses']),
        ];
    }
}
