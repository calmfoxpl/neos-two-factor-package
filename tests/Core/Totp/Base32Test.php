<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Tests\Core\Totp;

use Calmfox\TwoFactor\Core\Totp\Base32;
use Calmfox\TwoFactor\Core\Totp\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Base32Test extends TestCase
{
    /**
     * RFC 4648, section 10.
     *
     */
    #[DataProvider('rfcVectors')]
    public function testTheRfcTestVectors(string $plain, string $encoded): void
    {
        self::assertSame($encoded, Base32::encode($plain));
        self::assertSame($plain, Base32::decode($encoded));
    }

    /** @return iterable<string, array{string, string}> */
    public static function rfcVectors(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'f' => ['f', 'MY======'];
        yield 'fo' => ['fo', 'MZXQ===='];
        yield 'foo' => ['foo', 'MZXW6==='];
        yield 'foob' => ['foob', 'MZXW6YQ='];
        yield 'fooba' => ['fooba', 'MZXW6YTB'];
        yield 'foobar' => ['foobar', 'MZXW6YTBOI======'];
    }

    /** Apps print the key in groups of four, and people retype it in any case they like. */
    public function testASecretTypedByHandIsUnderstood(): void
    {
        self::assertSame('foobar', Base32::decode('mzxw 6ytb-oi'));
    }

    public function testTextThatIsNotBase32IsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Base32::decode('not base32!');
    }

    public function testAGeneratedSecretSurvivesTheRoundTrip(): void
    {
        $secret = Secret::generate();

        self::assertTrue(Secret::isWellFormed($secret));
        self::assertSame(20, \strlen(Base32::decode($secret)));
        self::assertNotSame($secret, Secret::generate());
    }

    public function testTheReadableFormIsTheSameSecret(): void
    {
        $secret = Secret::generate();

        self::assertSame(Base32::decode($secret), Base32::decode(Secret::readable($secret)));
    }

    public function testShortOrBrokenSecretsAreNotWellFormed(): void
    {
        foreach (['', 'MY======', 'nonsense!'] as $candidate) {
            self::assertFalse(Secret::isWellFormed($candidate), var_export($candidate, true));
        }
    }
}
