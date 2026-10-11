<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Crypto;

/**
 * Keeps the authenticator-app secret encrypted in the database.
 *
 * Flow has hashing and HMACs but, unlike Magento, no encryptor for values that have to be read
 * back, and a TOTP secret has to be: the server computes the same codes as the phone. A dump of
 * the table should not be enough to produce codes, so the secret is sealed with libsodium's
 * secretbox (XSalsa20-Poly1305), which also notices any change to the stored value.
 *
 * The key is derived from the installation's own secret (see SecretKey in the package), so
 * there is nothing new to configure and nothing to lose. That also means a new Flow encryption
 * key makes stored secrets unreadable: those users are then asked to pair their app again.
 */
final class SecretBox
{
    /** A version tag, so a later change of algorithm can still read what was written before. */
    private const PREFIX = 'sb1:';

    /**
     * A 32-byte key for one purpose, derived from secret material of any length.
     *
     * HKDF with a purpose label means the same installation secret can serve other uses
     * without any of them being able to decrypt the others' values.
     */
    public static function deriveKey(#[\SensitiveParameter] string $material, string $purpose): string
    {
        if ('' === $material) {
            throw new \InvalidArgumentException('The key material is empty.');
        }

        return hash_hkdf('sha256', $material, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $purpose);
    }

    public static function seal(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key): string
    {
        self::assertKey($key);
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    /** The plaintext, or null when the value was not sealed with this key or was changed since. */
    public static function open(string $sealed, #[\SensitiveParameter] string $key): ?string
    {
        self::assertKey($key);
        if (!str_starts_with($sealed, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($sealed, \strlen(self::PREFIX)), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        return false === $plaintext ? null : $plaintext;
    }

    private static function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException('The key must be 32 bytes long.');
        }
    }
}
