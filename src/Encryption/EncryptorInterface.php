<?php

namespace VeronaLabs\WpPremiumSdk\Encryption;

/**
 * Contract for encrypting sensitive plugin data at rest (license keys, OAuth tokens).
 *
 * Plugins may supply their own implementation (e.g. to reuse an existing key ring)
 * or rely on the SDK's default SodiumEncryptor.
 */
interface EncryptorInterface
{
    public function encrypt(string $plaintext): string;

    /**
     * @return string|null Null if decryption fails (e.g. key rotated or tampered ciphertext).
     */
    public function decrypt(string $ciphertext): ?string;
}
