<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Tests\Core\Passkey;

use Calmfox\TwoFactor\Core\Passkey\Credential;
use Calmfox\TwoFactor\Core\Passkey\CredentialSet;
use PHPUnit\Framework\TestCase;

final class CredentialSetTest extends TestCase
{
    public function testAKeyCanBeAddedFoundAndRemoved(): void
    {
        $set = (new CredentialSet())->add(self::credential('abc'));

        self::assertSame(1, $set->count());
        self::assertTrue($set->has('abc'));
        self::assertSame(['abc'], $set->ids());
        self::assertTrue($set->remove('abc')->isEmpty());
    }

    public function testTheSameDeviceIsNotPairedTwice(): void
    {
        $set = (new CredentialSet())->add(self::credential('abc'));

        $this->expectException(\RuntimeException::class);
        $set->add(self::credential('abc'));
    }

    public function testAnUnknownIdIsNotFound(): void
    {
        $set = (new CredentialSet())->add(self::credential('abc'));

        self::assertNull($set->find('other'));
        self::assertNull($set->find(''));
    }

    /** A private key is never stored, so it can never be read back out. */
    public function testOnlyPublicMaterialIsKept(): void
    {
        $stored = (new CredentialSet())->add(self::credential('abc'))->toArray();

        self::assertSame(['id', 'name', 'publicKey', 'signCount', 'createdAt', 'lastUsedAt'], array_keys($stored[0]));
    }

    public function testUsingAKeyUpdatesItsCounterAndDate(): void
    {
        $set = (new CredentialSet())->add(self::credential('abc'));
        $used = $set->find('abc')?->used(42, '2026-03-01T10:00:00+00:00');
        self::assertNotNull($used);

        $updated = $set->replace($used);

        self::assertSame(42, $updated->find('abc')?->signCount);
        self::assertSame('2026-03-01T10:00:00+00:00', $updated->find('abc')?->lastUsedAt);
        self::assertSame(1, $updated->count());
    }

    /** A counter that does not move forward means a replay, or a cloned device. */
    public function testACounterGoingBackwardsIsNoticed(): void
    {
        self::assertTrue(CredentialSet::counterWentBackwards(10, 9));
        self::assertTrue(CredentialSet::counterWentBackwards(10, 10));
        self::assertFalse(CredentialSet::counterWentBackwards(10, 11));
    }

    /** Some authenticators never count at all, and that is allowed. */
    public function testADeviceThatNeverCountsIsAccepted(): void
    {
        self::assertFalse(CredentialSet::counterWentBackwards(0, 0));
    }

    public function testRowsThatAreNotKeysAreDroppedRatherThanBreakingTheAccount(): void
    {
        $set = CredentialSet::fromArray([
            ['id' => 'abc', 'name' => 'iPhone', 'publicKey' => 'key'],
            ['name' => 'no id'],
            ['id' => 'def', 'publicKey' => ''],
            'not an array',
        ]);

        self::assertSame(['abc'], $set->ids());
    }

    public function testANamelessKeyStillGetsAName(): void
    {
        self::assertSame('Passkey', Credential::cleanName('   '));
        self::assertSame('iPhone Ewy', Credential::cleanName("  iPhone   Ewy \n"));
        self::assertSame(64, mb_strlen(Credential::cleanName(str_repeat('a', 100))));
    }

    public function testAKeyWithoutMaterialIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Credential('', 'x', 'key');
    }

    private static function credential(string $id): Credential
    {
        return new Credential($id, 'iPhone', 'public-key', 0, '2026-03-01T10:00:00+00:00');
    }
}
