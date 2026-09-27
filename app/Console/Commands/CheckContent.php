<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Runs database/sql/check_content.sql; a healthy database returns no rows. */
class CheckContent extends Command
{
    protected $signature = 'delve:check-content';

    protected $description = 'Check seeded content for broken references and out-of-range values';

    public function handle(): int
    {
        $sql = (string) file_get_contents(database_path('sql/check_content.sql'));
        $issues = [];
        foreach (self::statements($sql) as $statement) {
            foreach (DB::select($statement) as $row) {
                $issues[] = array_values((array) $row);
            }
        }
        if ($issues === []) {
            $this->info('Content OK: no issues found.');

            return 0;
        }
        $this->table(['Issue', 'Detail'], array_map(fn ($row) => [$row[0] ?? '', $row[1] ?? ''], $issues));

        return 1;
    }

    /** @return list<string> SELECT statements, with comments stripped */
    private static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($s) => $s !== ''));
    }
}
