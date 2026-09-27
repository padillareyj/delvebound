<?php

namespace App\Console\Commands;

use App\Game\Balance;
use App\Game\Rng;
use App\Game\Trip;
use App\Simulation\SimRepo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Offline trip Monte Carlo: reads content from MySQL, writes nothing (DESIGN.md §7.6). */
class BalanceLab extends Command
{
    protected $signature = 'delve:balance-lab
        {--place=greenwood : Place code}
        {--level=1 : Character level (1, 3 or 5 use a preset build)}
        {--trips=200 : Number of simulated trips}
        {--seed=1 : Base seed}';

    protected $description = 'Estimate clear, retreat, and defeat rates for a place';

    private const BUILDS = [
        1 => ['stats' => ['str' => 7, 'dex' => 6, 'int' => 5, 'vit' => 7, 'luk' => 5], 'gear' => ['rusty_sword', 'cloth_tunic', 'soft_boots']],
        3 => ['stats' => ['str' => 12, 'dex' => 8, 'int' => 5, 'vit' => 12, 'luk' => 5], 'gear' => ['rusty_sword', 'leather_jerkin', 'soft_boots', 'leather_cap']],
        5 => ['stats' => ['str' => 16, 'dex' => 10, 'int' => 5, 'vit' => 16, 'luk' => 6], 'gear' => ['iron_longsword', 'leather_jerkin', 'iron_helm', 'soft_boots']],
    ];

    public function handle(): int
    {
        $world = (new SimRepo(DB::connection()))->loadWorld();
        $balance = Balance::get();
        $place = collect($world['places'])->firstWhere('code', $this->option('place'));
        if (! $place) {
            $this->error('Unknown place. Choose one of: '.implode(', ', array_column($world['places'], 'code')));

            return 1;
        }
        $level = (int) $this->option('level');
        $trips = max(1, (int) $this->option('trips'));
        $seed = (int) $this->option('seed');
        $build = self::BUILDS[$level] ?? self::BUILDS[1];
        $byCode = array_column($world['templates'], null, 'code');
        $equipment = array_map(fn ($code) => $byCode[$code], $build['gear']);

        $counts = ['cleared' => 0, 'retreated' => 0, 'defeated' => 0];
        $xp = 0;
        $gold = 0;
        for ($i = 0; $i < $trips; $i++) {
            $ctx = [
                'character' => [
                    'id' => 900000 + $i, 'name' => 'Lab', 'level' => $level, 'xp' => 0, 'gold' => 0,
                    'stats' => $build['stats'], 'unspent' => 0, 'retreat_threshold_pct' => 25,
                    'potion_threshold_pct' => 40, 'injury_severity' => 0, 'injury_runs_left' => 0,
                    'best_place_rec' => null, 'trips_total' => 0, 'equipment' => $equipment, 'belt' => [],
                ],
                'place' => $place, 'town' => $world['town'], 'plan' => 'combat', 'order_id' => null,
                'reject_reason' => null, 'open_places' => [], 'affixes' => $world['affixes'],
                'inventory_count' => 0, 'commons' => [], 'balance' => $balance, 'run_seed' => $seed, 'run_id' => 0,
            ];
            $result = Trip::resolve($ctx, Rng::trip($seed + $i, 1));
            $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
            $xp += $result['xp_gained'];
            $gold += $result['gold_gained'];
        }

        $this->line("<options=bold>{$place['name']}</> at level {$level} over {$trips} trips");
        $this->table(['Outcome', 'Trips', 'Share'], array_map(
            fn ($outcome, $count) => [$outcome, $count, sprintf('%.1f%%', 100 * $count / $trips)],
            array_keys($counts), $counts,
        ));
        $this->line(sprintf('Average per trip: %.0f XP, %.0f gold', $xp / $trips, $gold / $trips));

        return 0;
    }
}
