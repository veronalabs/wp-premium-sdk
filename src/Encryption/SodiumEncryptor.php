<?php

namespace VeronaLabs\WpPremiumSdk\Encryption;

/**
 * Default EncryptorInterface implementation using libsodium secretbox.
 *
 * Derives a key from WordPress SALTs (AUTH_KEY etc.) when defined, otherwise
 * persists a random key in wp_options under {option_key}_cipher. Ciphertexts
 * include the nonce, base64-encoded, so they are self-contained.
 */
class SodiumEncryptor implements EncryptorInterface
{
    private ?string $cachedKey = null;

    public function __construct(private string $cipherOptionKey) {}

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return base64_encode($nonce.$cipher);
    }

    public function decrypt(string $ciphertext): ?string
    {
        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());

        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        if ($this->cachedKey !== null) {
            return $this->cachedKey;
        }

        $material = (defined('AUTH_KEY') ? AUTH_KEY : '').
                    (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '').
                    (defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '').
                    (defined('NONCE_KEY') ? NONCE_KEY : '').
                    (defined('AUTH_SALT') ? AUTH_SALT : '').
                    (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '').
                    (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '').
                    (defined('NONCE_SALT') ? NONCE_SALT : '');

        if ($material !== '') {
            return $this->cachedKey = sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }

        $stored = get_option($this->cipherOptionKey);
        $raw = is_string($stored) ? base64_decode($stored, true) : false;

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            update_option($this->cipherOptionKey, base64_encode($raw), true);
        }

        return $this->cachedKey = $raw;
    }
}
