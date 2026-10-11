<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Totp;

/**
 * Time-based one-time passwords, RFC 6238 over RFC 4226, with the parameters every
 * authenticator app uses by default: SHA-1, a 30-second step and six digits.
 *
 * Written out here rather than pulled in as a dependency: it is forty lines of arithmetic, a
 * package that guards the backend should not grow a dependency tree for them, and the RFC's own
 * test vectors keep it honest (see the tests).
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public const ALGORITHM = 'sha1';

    /** The code for a moment in time. */
    public static function at(#[\SensitiveParameter] string $secret, int $timestamp): string
    {
        return self::forCounter($secret, intdiv($timestamp, self::PERIOD));
    }

    /**
     * Whether a typed code is right for this moment, allowing for clocks that drift.
     *
     * A window of 1 accepts the previous and the next step as well, which is thirty seconds
     * either way — enough for a phone whose clock is a little off, and still only three codes
     * out of a million. The comparison is constant-time: a code is a secret for thirty seconds.
     */
    public static function verify(#[\SensitiveParameter] string $secret, string $code, int $timestamp, int $window = 1): bool
    {
        return null !== self::match($secret, $code, $timestamp, $window);
    }

    /**
     * Which step a typed code belongs to, or null when it belongs to none of the accepted ones.
     *
     * The caller needs the step, not just a yes: a code stays right for as long as its step
     * lasts, so the only way to stop the same six digits being used twice is to remember which
     * step was last accepted and refuse anything that is not newer.
     *
     * Where the window makes two steps match the same digits, which happens about once in a
     * million, the later one is reported. Taking the later one only ever moves the record
     * forward, and a code that cannot be used again is the safer half of the coincidence.
     */
    public static function match(#[\SensitiveParameter] string $secret, string $code, int $timestamp, int $window = 1): ?int
    {
        $code = trim($code);
        if (1 !== preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        if ($window < 0) {
            throw new \InvalidArgumentException('The window cannot be negative.');
        }

        $counter = intdiv($timestamp, self::PERIOD);
        $matched = null;
        // every candidate is checked, so the time taken says nothing about which one matched
        for ($offset = -$window; $offset <= $window; ++$offset) {
            if (hash_equals(self::forCounter($secret, $counter + $offset), $code)) {
                $matched = $counter + $offset;
            }
        }

        return $matched;
    }

    /** The otpauth:// URI behind the QR code, as Google Authenticator defined it. */
    public static function uri(#[\SensitiveParameter] string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /** RFC 4226: HMAC of the counter, then the dynamic truncation its section 5.3 describes. */
    private static function forCounter(#[\SensitiveParameter] string $secret, int $counter): string
    {
        $binarySecret = Base32::decode($secret);
        if ('' === $binarySecret) {
            throw new \InvalidArgumentException('The secret is empty.');
        }

        $hash = hash_hmac(self::ALGORITHM, pack('J', $counter), $binarySecret, true);
        $offset = \ord($hash[\strlen($hash) - 1]) & 0x0F;
        $truncated = ((\ord($hash[$offset]) & 0x7F) << 24)
            | (\ord($hash[$offset + 1]) << 16)
            | (\ord($hash[$offset + 2]) << 8)
            | \ord($hash[$offset + 3]);

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', \STR_PAD_LEFT);
    }
}
