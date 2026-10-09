<?php

namespace App\Game;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Seeded RNG (DESIGN.md §10.3 item 8). The same seed gives the same sequence in every PHP process.
 * Never use rand(), mt_rand(), random_int(), shuffle(), or array_rand() in app/Game or app/Simulation.
 */
final class Rng
{
    private const TWO_53 = 9007199254740992;

    private Randomizer $randomizer;

    public function __construct(string $seedText)
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar(hash('sha256', $seedText, true)));
    }

    public static function trip(int $seed, int $characterId): self
    {
        return new self("{$seed}:{$characterId}");
    }

    public static function bot(int $seed, int $characterId): self
    {
        return new self("{$seed}:bot:{$characterId}");
    }

    /** One sealed chest. The seed is drawn during the trip and stored on the box, so opening it later replays the same rolls. */
    public static function chest(int $seed): self
    {
        return new self("chest:{$seed}");
    }

    /** One newcomer born at the start of a run. The bot count keeps the roll stable if the insert id changes. */
    public static function arrival(int $seed, int $activeBots): self
    {
        return new self("{$seed}:arrival:{$activeBots}");
    }

    /** One generated region. The key is the frontier place plus the new map's order, so the map does not depend on the run seed. */
    public static function world(string $key): self
    {
        return new self("world:{$key}");
    }

    /** One forge attempt. Item ids are sorted so the sequence does not depend on which piece was placed first. */
    public static function forge(int $seed, int $characterId, int $itemA, int $itemB): self
    {
        $low = min($itemA, $itemB);
        $high = max($itemA, $itemB);

        return new self("{$seed}:forge:{$characterId}:{$low}:{$high}");
    }

    /** Bracket shuffle for one arena (DESIGN.md §15). */
    public static function arena(int $seed): self
    {
        return new self("{$seed}:arena");
    }

    /** One duel. Character ids are sorted so the sequence does not depend on pairing order. */
    public static function duel(int $seed, int $round, int $slot, int $characterA, int $characterB): self
    {
        $low = min($characterA, $characterB);
        $high = max($characterA, $characterB);

        return new self("{$seed}:duel:{$round}:{$slot}:{$low}:{$high}");
    }

    /** Float in [0, 1). */
    public function random(): float
    {
        return $this->randomizer->getInt(0, self::TWO_53 - 1) / self::TWO_53;
    }

    /** Integer in [a, b], both ends included. */
    public function randint(int $a, int $b): int
    {
        return $this->randomizer->getInt($a, $b);
    }

    public function uniform(float $a, float $b): float
    {
        return $a + ($b - $a) * $this->random();
    }

    public function choice(array $items): mixed
    {
        $items = array_values($items);

        return $items[$this->randint(0, count($items) - 1)];
    }

    /** Normal distribution (Box-Muller). */
    public function gauss(float $mu, float $sigma): float
    {
        $u1 = 1.0 - $this->random();
        $u2 = $this->random();

        return $mu + $sigma * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
