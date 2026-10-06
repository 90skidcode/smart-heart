<?php

namespace App\Console\Commands;

use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Encrypted logical backup of every table, without needing mysqldump
 * (often unavailable on shared hosting).
 *
 *   php artisan shi:backup            create storage/app/backups/shi_YYYYmmdd_His.json.gz.enc
 *   php artisan shi:backup --decrypt=FILE   write the plain .json.gz next to it (for restore checks)
 *
 * Encryption: AES-256-CBC with BACKUP_KEY from .env (falls back to APP_KEY).
 * Keep BACKUP_KEY somewhere safe and separate from the server — without it,
 * backups cannot be read.
 */
class BackupDatabase extends Command
{
    protected $signature = 'shi:backup {--decrypt= : Path of an .enc backup to decrypt}';

    protected $description = 'Create an encrypted backup of the trial database (or decrypt one).';

    public function handle(): int
    {
        if ($file = $this->option('decrypt')) {
            return $this->decrypt($file);
        }

        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $dump = ['created_at' => now()->toIso8601String(), 'app' => config('app.name'), 'tables' => []];
        foreach ($this->tables() as $table) {
            $dump['tables'][$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        }
        $json = json_encode($dump, JSON_UNESCAPED_UNICODE);
        $gz = gzencode($json, 9);

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($gz, 'aes-256-cbc', $this->key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        $name = 'shi_'.now()->format('Ymd_His').'.json.gz.enc';
        $mac = hash_hmac('sha256', $iv.$cipher, $this->key(), true);
        file_put_contents("$dir/$name", 'SHIBK1'.$iv.$mac.$cipher);

        // Retention
        $keep = config('smartheart.backup.keep_days');
        foreach (glob("$dir/shi_*.json.gz.enc") as $old) {
            if (filemtime($old) < now()->subDays($keep)->getTimestamp()) {
                unlink($old);
            }
        }

        $counts = array_map('count', $dump['tables']);
        Audit::log('backup_created', ['meta' => ['file' => $name, 'bytes' => filesize("$dir/$name"), 'rows' => $counts]]);
        $this->info("Backup written: storage/app/backups/$name");

        return self::SUCCESS;
    }

    private function decrypt(string $file): int
    {
        $raw = file_get_contents($file);
        if ($raw === false || ! str_starts_with($raw, 'SHIBK1')) {
            $this->error('Not a SMART-HEART backup file.');

            return self::FAILURE;
        }
        $iv = substr($raw, 6, 16);
        $mac = substr($raw, 22, 32);
        $cipher = substr($raw, 54);
        if (! hash_equals($mac, hash_hmac('sha256', $iv.$cipher, $this->key(), true))) {
            $this->error('Integrity check failed — wrong BACKUP_KEY or damaged file.');

            return self::FAILURE;
        }
        $gz = openssl_decrypt($cipher, 'aes-256-cbc', $this->key(), OPENSSL_RAW_DATA, $iv);
        $out = preg_replace('/\.enc$/', '', $file);
        file_put_contents($out, $gz);
        $this->info("Decrypted to $out (gzip-compressed JSON, one key per table).");

        return self::SUCCESS;
    }

    private function key(): string
    {
        $k = config('smartheart.backup.key') ?: config('app.key');
        if (str_starts_with($k, 'base64:')) {
            $k = base64_decode(substr($k, 7));
        }

        return hash('sha256', $k, true);
    }

    private function tables(): array
    {
        $skip = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'api_tokens', 'password_reset_tokens'];
        $names = match (DB::getDriverName()) {
            'sqlite' => array_column(array_map(fn ($r) => (array) $r,
                DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")), 'name'),
            default => array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW TABLES')),
        };

        return array_values(array_diff($names, $skip, ['migrations']));
    }
}
