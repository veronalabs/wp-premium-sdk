<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Encryption;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

class SodiumEncryptorTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    public function test_round_trip_encrypts_and_decrypts(): void
    {
        $encryptor = new SodiumEncryptor('test_cipher');

        $cipher = $encryptor->encrypt('license-ABC-123');

        $this->assertNotSame('license-ABC-123', $cipher);
        $this->assertSame('license-ABC-123', $encryptor->decrypt($cipher));
    }

    public function test_ciphertext_differs_between_encryptions_of_same_plaintext(): void
    {
        $encryptor = new SodiumEncryptor('test_cipher');

        $a = $encryptor->encrypt('same-input');
        $b = $encryptor->encrypt('same-input');

        $this->assertNotSame($a, $b, 'Nonce randomization should produce different ciphertexts.');
        $this->assertSame('same-input', $encryptor->decrypt($a));
        $this->assertSame('same-input', $encryptor->decrypt($b));
    }

    public function test_decrypt_returns_null_for_tampered_ciphertext(): void
    {
        $encryptor = new SodiumEncryptor('test_cipher');
        $cipher = $encryptor->encrypt('secret');

        $tampered = substr($cipher, 0, -4).'XXXX';

        $this->assertNull($encryptor->decrypt($tampered));
    }

    public function test_decrypt_returns_null_for_truncated_payload(): void
    {
        $encryptor = new SodiumEncryptor('test_cipher');

        $this->assertNull($encryptor->decrypt('too-short'));
    }

    public function test_persists_fallback_key_when_salts_undefined(): void
    {
        $encryptor = new SodiumEncryptor('test_cipher');
        $encryptor->encrypt('first-value');

        $this->assertArrayHasKey('test_cipher', WpStub::$options, 'Fallback key should persist when WP SALTs are absent.');

        // A fresh encryptor should reuse the stored key and still decrypt prior output.
        $encryptor->encrypt('second-value');
        $persisted = WpStub::$options['test_cipher'];

        $this->assertIsString($persisted);
        $this->assertNotSame('', $persisted);
    }
}
