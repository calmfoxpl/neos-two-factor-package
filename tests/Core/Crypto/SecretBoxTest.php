<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Tests\Core\Crypto;

use Calmfox\TwoFactor\Core\Crypto\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testWhatIsSealedCanBeOpenedWithTheSameKey(): void
    {
        $key = SecretBox::deriveKey('installation secret', 'totp');

        self::assertSame('JBSWY3DPEHPK3PXP', SecretBox::open(SecretBox::seal('JBSWY3DPEHPK3PXP', $key), $key));
    }

    public function testTheStoredValueDoesNotContainTheSecret(): void
    {
        $sealed = SecretBox::seal('JBSWY3DPEHPK3PXP', SecretBox::deriveKey('installation secret', 'totp'));

        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $sealed);
        self::assertStringNotContainsString(base64_encode('JBSWY3DPEHPK3PXP'), $sealed);
    }

    public function testSealingTwiceGivesTwoDifferentValues(): void
    {
        $key = SecretBox::deriveKey('installation secret', 'totp');

        self::assertNotSame(SecretBox::seal('same', $key), SecretBox::seal('same', $key));
    }

    public function testAnotherInstallationCannotOpenIt(): void
    {
        $sealed = SecretBox::seal('secret', SecretBox::deriveKey('installation A', 'totp'));

        self::assertNull(SecretBox::open($sealed, SecretBox::deriveKey('installation B', 'totp')));
    }

    public function testAnotherPurposeCannotOpenIt(): void
    {
        $sealed = SecretBox::seal('secret', SecretBox::deriveKey('installation', 'totp'));

        self::assertNull(SecretBox::open($sealed, SecretBox::deriveKey('installation', 'something else')));
    }

    public function testAChangedValueIsRefusedRatherThanMisread(): void
    {
        $key = SecretBox::deriveKey('installation', 'totp');
        $sealed = SecretBox::seal('secret', $key);
        $raw = base64_decode(substr($sealed, 4), true);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 1);

        self::assertNull(SecretBox::open('sb1:' . base64_encode($raw), $key));
    }

    public function testGarbageIsRefused(): void
    {
        $key = SecretBox::deriveKey('installation', 'totp');

        self::assertNull(SecretBox::open('', $key));
        self::assertNull(SecretBox::open('JBSWY3DPEHPK3PXP', $key));
        self::assertNull(SecretBox::open('sb1:not base64 at all!', $key));
        self::assertNull(SecretBox::open('sb1:' . base64_encode('short'), $key));
    }

    public function testAKeyOfTheWrongLengthIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecretBox::seal('secret', 'too short');
    }
}
