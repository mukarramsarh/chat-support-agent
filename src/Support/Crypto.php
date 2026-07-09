<?php

declare(strict_types=1);

namespace SupportAI\Support;

use RuntimeException;

/**
 * Authenticated symmetric encryption for secrets stored in the DB
 * (provider API keys, Pinecone key). Uses libsodium when available and
 * falls back to AES-256-GCM via OpenSSL, both AEAD constructions.
 *
 * The key is derived from APP_KEY so rotating APP_KEY invalidates stored
 * secrets by design.
 */
final class Crypto
{
    private string $key;

    public function __construct(string $appKey)
    {
        if (strlen($appKey) < 16) {
            throw new RuntimeException('APP_KEY must be set (32+ chars recommended) to store secrets.');
        }
        // Normalise to a 32-byte key.
        $this->key = hash('sha256', $appKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
            return 'v1.sodium.' . base64_encode($nonce . $cipher);
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return 'v1.aesgcm.' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        [$version, $scheme, $data] = array_pad(explode('.', $payload, 3), 3, '');
        if ($version !== 'v1' || $data === '') {
            throw new RuntimeException('Malformed ciphertext.');
        }
        $raw = base64_decode($data, true);
        if ($raw === false) {
            throw new RuntimeException('Malformed ciphertext.');
        }

        if ($scheme === 'sodium') {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
            if ($plain === false) {
                throw new RuntimeException('Decryption failed.');
            }
            return $plain;
        }

        if ($scheme === 'aesgcm') {
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) {
                throw new RuntimeException('Decryption failed.');
            }
            return $plain;
        }

        throw new RuntimeException("Unknown cipher scheme: {$scheme}");
    }
}
