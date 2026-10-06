<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use RuntimeException;

/**
 * AES-256-GCM for encrypted columns (TOTP secrets now; names, phones and emails later).
 * Layout: version (0x01) | key id | 12-byte nonce | 16-byte tag | ciphertext.
 * `$context` is authenticated but not stored (e.g. "users.mfa_secret:12"), so a value
 * copied into another row or column fails to decrypt.
 */
final readonly class Crypto
{
    private const VERSION = "\x01";

    public function __construct(private Secrets $secrets)
    {
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $this->secrets->dataKey, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed');
        }
        return self::VERSION . chr($this->secrets->dataKeyId) . $nonce . $tag . $ct;
    }

    /**
     * Keyed hash (hex) for values that must be looked up but never read back, such as recovery codes
     * and blind indexes. Each purpose gets its own subkey, so hashes from one purpose are useless in another.
     */
    public function mac(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $value, hash_hmac('sha256', $purpose, $this->secrets->dataKey, true));
    }

    public function decrypt(string $blob, string $context): string
    {
        if (strlen($blob) < 30 || $blob[0] !== self::VERSION) {
            throw new RuntimeException('Not an encrypted value');
        }
        if (ord($blob[1]) !== $this->secrets->dataKeyId) {
            throw new RuntimeException('Encrypted with key id ' . ord($blob[1]) . ', which is not configured');
        }
        $pt = openssl_decrypt(substr($blob, 30), 'aes-256-gcm', $this->secrets->dataKey, OPENSSL_RAW_DATA, substr($blob, 2, 12), substr($blob, 14, 16), $context);
        if ($pt === false) {
            throw new RuntimeException('Decryption failed: wrong key, wrong context or tampered value');
        }
        return $pt;
    }
}
