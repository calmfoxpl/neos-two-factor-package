<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Tests\Core\Totp;

use Calmfox\TwoFactor\Core\Totp\Base32;
use Calmfox\TwoFactor\Core\Totp\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The implementation is checked against RFC 6238's own test vectors. If these pass, every
 * authenticator app in the world agrees with us; if they fail, nothing else about the module
 * matters.
 */
final class TotpTest extends TestCase
{
    /** The seed the RFC uses for SHA-1: the ASCII string "12345678901234567890". */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testTheRfcSecretIsTheOneTheRfcMeans(): void
    {
        self::assertSame('12345678901234567890', Base32::decode(self::RFC_SECRET));
    }

    /**
     * RFC 6238, appendix B. The document prints eight digits; six-digit codes, which is what
     * apps show, are the last six of each.
     *
     */
    #[DataProvider('rfcVectors')]
    public function testTheRfcTestVectors(int $timestamp, string $expected): void
    {
        self::assertSame($expected, Totp::at(self::RFC_SECRET, $timestamp));
    }

    /** @return iterable<string, array{int, string}> */
    public static function rfcVectors(): iterable
    {
        yield '1970-01-01 00:00:59' => [59, '287082'];
        yield '2005-03-18 01:58:29' => [1111111109, '081804'];
        yield '2005-03-18 01:58:31' => [1111111111, '050471'];
        yield '2009-02-13 23:31:30' => [1234567890, '005924'];
        yield '2033-05-18 03:33:20' => [2000000000, '279037'];
        yield '2603-10-11 11:33:20' => [20000000000, '353130'];
    }

    public function testACodeIsValidForItsOwnMoment(): void
    {
        self::assertTrue(Totp::verify(self::RFC_SECRET, '050471', 1111111111, 0));
    }

    /** A phone whose clock drifts by half a minute still works. */
    public function testTheWindowAcceptsTheNeighbouringSteps(): void
    {
        $code = Totp::at(self::RFC_SECRET, 1111111111);

        self::assertTrue(Totp::verify(self::RFC_SECRET, $code, 1111111111 + Totp::PERIOD, 1));
        self::assertTrue(Totp::verify(self::RFC_SECRET, $code, 1111111111 - Totp::PERIOD, 1));
    }

    /** Two steps away is not drift any more. */
    public function testTheWindowDoesNotStretchFurther(): void
    {
        $code = Totp::at(self::RFC_SECRET, 1111111111);

        self::assertFalse(Totp::verify(self::RFC_SECRET, $code, 1111111111 + 2 * Totp::PERIOD, 1));
    }

    public function testAWindowOfZeroAcceptsOnlyTheCurrentStep(): void
    {
        $code = Totp::at(self::RFC_SECRET, 1111111111);

        self::assertFalse(Totp::verify(self::RFC_SECRET, $code, 1111111111 + Totp::PERIOD, 0));
    }

    public function testAnotherSecretNeverMatches(): void
    {
        self::assertFalse(Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJA', '050471', 1111111111));
    }

    /** Anything that is not six digits is refused before a secret is even touched. */
    public function testMalformedCodesAreRefused(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '05047 1', '05-471'] as $code) {
            self::assertFalse(Totp::verify(self::RFC_SECRET, $code, 1111111111), var_export($code, true));
        }
    }

    /** People paste codes with a stray space around them. */
    public function testSurroundingWhitespaceIsForgiven(): void
    {
        self::assertTrue(Totp::verify(self::RFC_SECRET, "  050471\n", 1111111111, 0));
    }

    public function testANegativeWindowIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Totp::verify(self::RFC_SECRET, '050471', 1111111111, -1);
    }

    public function testTheUriCarriesEverythingAnAppNeeds(): void
    {
        $uri = Totp::uri(self::RFC_SECRET, 'ewa@przyklad.test', 'Sklep Testowy');

        self::assertStringStartsWith('otpauth://totp/Sklep%20Testowy:ewa%40przyklad.test?', $uri);
        self::assertStringContainsString('secret=' . self::RFC_SECRET, $uri);
        self::assertStringContainsString('issuer=Sklep%20Testowy', $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function testAnEmptySecretIsRefusedRatherThanProducingACode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Totp::at('', 1111111111);
    }

    /**
     * A code has to be spendable exactly once, and the only thing that tells two uses of the
     * same six digits apart is the step they belong to. So the arithmetic has to report it.
     */
    public function testMatchReportsWhichStepACodeBelongsTo(): void
    {
        $timestamp = 1_700_000_000;
        $step = intdiv($timestamp, Totp::PERIOD);
        $code = Totp::at(self::RFC_SECRET, $timestamp);

        self::assertSame($step, Totp::match(self::RFC_SECRET, $code, $timestamp, 1));
    }

    /** The step of a code from a moment ago is the earlier one, not the current one. */
    public function testMatchReportsTheStepTheCodeCameFromNotTheStepItWasCheckedIn(): void
    {
        $timestamp = 1_700_000_000;
        $earlier = $timestamp - Totp::PERIOD;
        $code = Totp::at(self::RFC_SECRET, $earlier);

        self::assertSame(intdiv($earlier, Totp::PERIOD), Totp::match(self::RFC_SECRET, $code, $timestamp, 1));
    }

    public function testMatchReportsNothingForACodeFromOutsideTheWindow(): void
    {
        $timestamp = 1_700_000_000;
        $tooOld = Totp::at(self::RFC_SECRET, $timestamp - 10 * Totp::PERIOD);

        self::assertNull(Totp::match(self::RFC_SECRET, $tooOld, $timestamp, 1));
    }

    public function testMatchReportsNothingForSomethingThatIsNotSixDigits(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 34 56'] as $notACode) {
            self::assertNull(Totp::match(self::RFC_SECRET, $notACode, 1_700_000_000, 1), $notACode);
        }
    }

    /** verify() is the same question asked with a yes or no, so the two must never disagree. */
    public function testVerifyAgreesWithMatch(): void
    {
        $timestamp = 1_700_000_000;

        foreach ([-1, 0, 1, 5] as $offset) {
            $code = Totp::at(self::RFC_SECRET, $timestamp + $offset * Totp::PERIOD);

            self::assertSame(
                null !== Totp::match(self::RFC_SECRET, $code, $timestamp, 1),
                Totp::verify(self::RFC_SECRET, $code, $timestamp, 1),
                'offset ' . $offset,
            );
        }
    }
}
