<?php

declare(strict_types=1);

// Prints a fresh set of keys as KEY=value lines. Local: php bin/generate-secrets.php >> .env
// Servers: put the values in the environment (never in the repository), one set per environment.

$b64 = static fn() => base64_encode(random_bytes(32));
echo "JWT_SIGNING_KEY_ID=k1\n";
echo 'JWT_SIGNING_KEY=' . $b64() . "\n";
echo "CASE_NUMBER_PEPPER_CURRENT=1\n";
echo 'CASE_NUMBER_PEPPER_1=' . $b64() . "\n";
echo "DATA_ENCRYPTION_KEY_ID=1\n";
echo 'DATA_ENCRYPTION_KEY=' . $b64() . "\n";
