<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Totp;

/** A new authenticator-app secret, and the way it is shown to somebody typing it in by hand. */
final class Secret
{
    /** 20 bytes: the length RFC 4226 recommends for SHA-1, and what apps expect. */
    private const BYTES = 20;

    public static function generate(): string
    {
        return rtrim(Base32::encode(random_bytes(self::BYTES)), '=');
    }

    /** Groups of four, the way authenticator apps print a key people have to retype. */
    public static function readable(#[\SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function isWellFormed(string $secret): bool
    {
        try {
            return 16 <= \strlen(Base32::decode($secret));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
