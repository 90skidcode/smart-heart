<?php

declare(strict_types=1);

namespace SmartHeart;

use InvalidArgumentException;
use SmartHeart\Infra\EnvFile;

/** Non-secret settings from the environment (keys live in Infra\Secrets). */
final readonly class Config
{
    public function __construct(
        public string $env,
        public string $version,
        public string $problemBaseUri,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public ?string $dbSocket,
        public string $studyHelpline = '[STUDY HELPLINE NUMBER]',
    ) {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
            throw new InvalidArgumentException("DB_NAME must be letters, digits and underscores: $dbName");
        }
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $env = EnvFile::read($envFile);
        $get = static fn(string $key, string $default): string => $env($key) ?? $default;

        return new self(
            env: $get('APP_ENV', 'local'),
            version: $get('APP_VERSION', '1.0.0'),
            problemBaseUri: rtrim($get('PROBLEM_BASE_URI', 'https://api.smartheart.example.in/problems'), '/'),
            dbHost: $get('DB_HOST', '127.0.0.1'),
            dbPort: (int) $get('DB_PORT', '3306'),
            dbName: $get('DB_NAME', 'smart_heart'),
            dbUser: $get('DB_USER', 'root'),
            dbPassword: $get('DB_PASSWORD', ''),
            dbSocket: $get('DB_SOCKET', '') ?: null,
            studyHelpline: $get('STUDY_HELPLINE', '[STUDY HELPLINE NUMBER]'),
        );
    }

    public function isProduction(): bool
    {
        return $this->env === 'production';
    }
}
