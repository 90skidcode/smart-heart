<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

/**
 * Environment lookup: real environment variables first, then a local `.env` file (KEY=VALUE lines).
 * Servers set real variables; the file is for local development only.
 */
final readonly class EnvFile
{
    /** @param array<string, string> $file */
    private function __construct(private array $file)
    {
    }

    public static function read(?string $path): self
    {
        $values = [];
        if ($path !== null && is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $values[$key] = trim($value, "\"'");
            }
        }
        return new self($values);
    }

    public function __invoke(string $key): ?string
    {
        $value = getenv($key);
        return $value !== false ? $value : ($this->file[$key] ?? null);
    }

    /** @return array<string, string> every variable, the real environment winning */
    public function all(): array
    {
        return getenv() + $this->file;
    }
}
