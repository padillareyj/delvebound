<?php

namespace App\Game;

use RuntimeException;

/** Loads config/balance.json, the one file with every tunable number (DESIGN.md, AGENTS.md §4). */
final class Balance
{
    private static ?array $cached = null;

    public static function get(): array
    {
        if (self::$cached === null) {
            $path = function_exists('config') ? (string) config('delvebound.balance_file', 'config/balance.json') : 'config/balance.json';
            self::$cached = self::load($path);
        }

        return self::$cached;
    }

    public static function load(string $path): array
    {
        if (! str_starts_with($path, '/')) {
            $path = (function_exists('base_path') ? base_path() : dirname(__DIR__, 2)).'/'.$path;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Balance file not found: {$path}");
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function forget(): void
    {
        self::$cached = null;
    }
}
