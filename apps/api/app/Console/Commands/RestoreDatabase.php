<?php

namespace App\Console\Commands;

use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore a decrypted backup (.json.gz from "shi:backup --decrypt") into the
 * current database. Intended for the monthly restore test on a SEPARATE test
 * database, or for disaster recovery. It replaces all study data.
 *
 *   php artisan migrate:fresh --force        (empty schema first)
 *   php artisan shi:restore storage/app/backups/shi_....json.gz
 */
class RestoreDatabase extends Command
{
    protected $signature = 'shi:restore {file : Decrypted .json.gz backup} {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a decrypted SMART-HEART backup into the current database (replaces all data).';

    public function handle(): int
    {
        $raw = @gzdecode((string) file_get_contents($this->argument('file')));
        $dump = $raw ? json_decode($raw, true) : null;
        if (! is_array($dump['tables'] ?? null)) {
            $this->error('Not a readable decrypted backup (.json.gz). Run shi:backup --decrypt first.');

            return self::FAILURE;
        }

        $db = config('database.connections.'.config('database.default').'.database');
        $this->warn("This REPLACES all data in database [{$db}] with the backup from {$dump['created_at']}.");
        if (! $this->option('force') && ! $this->confirm('Type yes to continue', false)) {
            return self::FAILURE;
        }

        Schema::disableForeignKeyConstraints();
        DB::transaction(function () use ($dump) {
            foreach ($dump['tables'] as $table => $rows) {
                if (! Schema::hasTable($table)) {
                    $this->warn("Skipping unknown table {$table}");
                    continue;
                }
                DB::table($table)->delete();
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
                $this->line(sprintf('  %-20s %6d rows', $table, count($rows)));
            }
        });
        Schema::enableForeignKeyConstraints();

        Audit::log('backup_restored', ['meta' => ['backup_created_at' => $dump['created_at'], 'rows' => array_map('count', $dump['tables'])]]);
        $this->info('Restore complete. Sign-in sessions were not restored; users must sign in again.');

        return self::SUCCESS;
    }
}
