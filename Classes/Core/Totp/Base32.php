<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Totp;

/**
 * RFC 4648 base32, the alphabet every authenticator app expects when a secret is typed in by
 * hand. Only what a TOTP secret needs: no line wrapping, no other alphabets.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(#[\SensitiveParameter] string $binary): string
    {
        if ('' === $binary) {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', \STR_PAD_RIGHT))];
        }

        // pad to a whole number of 8-character blocks, as the RFC asks
        return str_pad($encoded, (int) (ceil(\strlen($encoded) / 8) * 8), '=');
    }

    /** @throws \InvalidArgumentException when the text is not base32 at all */
    public static function decode(#[\SensitiveParameter] string $encoded): string
    {
        // apps show secrets in groups of four; people retype them in any case and with spaces
        $encoded = strtoupper(preg_replace('/[\s-]+/', '', $encoded) ?? '');
        $encoded = rtrim($encoded, '=');

        if ('' === $encoded) {
            return '';
        }
        if (1 !== preg_match('/^[A-Z2-7]+$/', $encoded)) {
            throw new \InvalidArgumentException('The secret is not a base32 string.');
        }

        $bits = '';
        foreach (str_split($encoded) as $character) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $character)), 5, '0', \STR_PAD_LEFT);
        }

        $binary = '';
        // a trailing group shorter than 8 bits is padding, not data
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $binary .= \chr(bindec($chunk));
            }
        }

        return $binary;
    }
}
