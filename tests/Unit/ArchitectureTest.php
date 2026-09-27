<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Guards the rules in AGENTS.md §2 and §4 that are easy to break by accident. */
class ArchitectureTest extends TestCase
{
    private static function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** Strips comments and strings so a mention in a docblock doesn't count. */
    private static function code(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    public function test_simulation_makes_no_network_or_shell_calls(): void
    {
        $forbidden = '/\b(curl_\w+|fsockopen|stream_socket_client|socket_\w+|file_get_contents\s*\(\s*[\'"]https?:|exec|shell_exec|system|passthru|proc_open|popen)\s*\(|Illuminate\\\\Support\\\\Facades\\\\Http|GuzzleHttp|\bHttp::/';
        foreach ([...self::phpFiles('app/Game'), ...self::phpFiles('app/Simulation')] as $path) {
            $this->assertDoesNotMatchRegularExpression($forbidden, self::code($path), basename($path).' reaches outside the database');
        }
    }

    public function test_game_core_uses_only_the_seeded_rng(): void
    {
        $forbidden = '/\b(rand|mt_rand|random_int|random_bytes|shuffle|array_rand|str_shuffle|lcg_value|mt_srand|srand|uniqid)\s*\(/';
        foreach ([...self::phpFiles('app/Game'), ...self::phpFiles('app/Simulation')] as $path) {
            $this->assertDoesNotMatchRegularExpression($forbidden, self::code($path), basename($path).' uses unseeded randomness');
        }
    }

    public function test_pure_core_has_no_database_access(): void
    {
        foreach (self::phpFiles('app/Game') as $path) {
            $this->assertDoesNotMatchRegularExpression('/\bDB::|Illuminate\\\\Support\\\\Facades\\\\DB|\bPDO\b|->table\(/', self::code($path), basename($path).' touches the database');
        }
    }

    public function test_website_cannot_start_a_run(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');
        $this->assertDoesNotMatchRegularExpression('/delve:run|delve:arena|Runner|Artisan::call|simulation/i', preg_replace('#//.*#', '', $routes));
        foreach (self::phpFiles('app/Http') as $path) {
            $this->assertDoesNotMatchRegularExpression('/\bRunner\b|\bArenaRunner\b|Artisan::call|Process::/', self::code($path), basename($path).' could start a run');
        }
    }
}
