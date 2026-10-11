<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Tests\Core\Policy;

use Calmfox\TwoFactor\Core\Policy\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public function testRequiredMakesEveryoneSetUpAndEveryoneAnswer(): void
    {
        $policy = new Policy(Policy::REQUIRED);

        self::assertTrue($policy->mustSetUp(hasMethod: false, setupRequired: false));
        self::assertTrue($policy->challenges(hasMethod: true));
        self::assertFalse($policy->mustSetUp(hasMethod: true, setupRequired: false));
    }

    public function testOptionalLeavesTheChoiceToEachUser(): void
    {
        $policy = new Policy(Policy::OPTIONAL);

        self::assertFalse($policy->mustSetUp(hasMethod: false, setupRequired: false));
        self::assertTrue($policy->allowsSetUp(hasMethod: false));
        self::assertTrue($policy->challenges(hasMethod: true));
    }

    /** Somebody decided this account needs a new device; an optional policy must not drop that. */
    public function testAResetOutranksAnOptionalPolicy(): void
    {
        self::assertTrue((new Policy(Policy::OPTIONAL))->mustSetUp(hasMethod: false, setupRequired: true));
    }

    public function testDisabledAsksNobodyForAnything(): void
    {
        $policy = new Policy(Policy::DISABLED);

        self::assertFalse($policy->isActive());
        self::assertFalse($policy->challenges(hasMethod: true));
        self::assertFalse($policy->mustSetUp(hasMethod: false, setupRequired: true));
        self::assertFalse($policy->allowsSetUp(hasMethod: false));
    }

    /** Turning the policy back on asks for the methods that were paired all along. */
    public function testPairedMethodsSurviveThePolicyBeingTurnedOff(): void
    {
        self::assertFalse((new Policy(Policy::DISABLED))->challenges(hasMethod: true));
        self::assertTrue((new Policy(Policy::REQUIRED))->challenges(hasMethod: true));
    }

    public function testAUserWithoutAMethodIsNeverChallenged(): void
    {
        foreach (Policy::ALL as $name) {
            self::assertFalse((new Policy($name))->challenges(hasMethod: false), $name);
        }
    }

    public function testAnUnknownPolicyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Policy('sometimes');
    }

    public function testNextSendsAUserWithoutAMethodToSetUpUnderARequiredPolicy(): void
    {
        self::assertSame(Policy::SET_UP, (new Policy(Policy::REQUIRED))->next(hasMethod: false, setupRequired: false, answered: false));
        self::assertSame(Policy::NOTHING, (new Policy(Policy::OPTIONAL))->next(hasMethod: false, setupRequired: false, answered: false));
    }

    public function testNextAsksForTheAnswerOnlyUntilItIsGiven(): void
    {
        $policy = new Policy(Policy::OPTIONAL);

        self::assertSame(Policy::ANSWER, $policy->next(hasMethod: true, setupRequired: false, answered: false));
        self::assertSame(Policy::NOTHING, $policy->next(hasMethod: true, setupRequired: false, answered: true));
    }

    /** An answer given in this session does not excuse a pairing that a colleague demanded. */
    public function testNextPutsAResetBeforeAnAnswer(): void
    {
        self::assertSame(Policy::SET_UP, (new Policy(Policy::OPTIONAL))->next(hasMethod: false, setupRequired: true, answered: true));
    }

    public function testNextAsksNothingWhenTheGuardIsTurnedOff(): void
    {
        self::assertSame(Policy::NOTHING, (new Policy(Policy::DISABLED))->next(hasMethod: true, setupRequired: true, answered: false));
    }
}
