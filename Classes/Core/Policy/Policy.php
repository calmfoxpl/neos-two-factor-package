<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Policy;

/**
 * Who has to use a second factor:
 *
 *  - required: every backend user; without a paired method they only reach the setup page,
 *  - optional: whoever paired one, plus anyone whose second factor was reset by a colleague,
 *  - disabled: nobody is asked; paired methods stay on the accounts and work again when the
 *    policy changes back.
 *
 * Plain decisions over plain values, so the rules can be read in one place and tested without
 * a database, a session or a logged-in user.
 */
final class Policy
{
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const DISABLED = 'disabled';

    public const ALL = [self::REQUIRED, self::OPTIONAL, self::DISABLED];

    /** What still has to happen before a signed-in user may use the backend: nothing, */
    public const NOTHING = 'nothing';

    /** ...pairing a second factor, */
    public const SET_UP = 'set_up';

    /** ...or answering the one they paired. */
    public const ANSWER = 'answer';

    public function __construct(private readonly string $policy)
    {
        if (!\in_array($policy, self::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown two-factor policy "%s".', $policy));
        }
    }

    public function name(): string
    {
        return $this->policy;
    }

    public function isActive(): bool
    {
        return self::DISABLED !== $this->policy;
    }

    /** Is this user asked for a second factor after their password? */
    public function challenges(bool $hasMethod): bool
    {
        return $this->isActive() && $hasMethod;
    }

    /**
     * Must this user pair a method before they can use the panel?
     *
     * "Reset by a colleague" outranks an optional policy: somebody decided this account needs
     * a new device, and an optional policy must not quietly drop that.
     */
    public function mustSetUp(bool $hasMethod, bool $setupRequired): bool
    {
        if (!$this->isActive() || $hasMethod) {
            return false;
        }

        return self::REQUIRED === $this->policy || $setupRequired;
    }

    /**
     * The one question the guard, the controllers and the templates all ask, answered in one
     * place, so that "may I be here?" cannot be decided one way on a page and another way in
     * the guard that protects it.
     *
     * @return self::NOTHING|self::SET_UP|self::ANSWER
     */
    public function next(bool $hasMethod, bool $setupRequired, bool $answered): string
    {
        if ($this->mustSetUp($hasMethod, $setupRequired)) {
            return self::SET_UP;
        }
        if ($this->challenges($hasMethod) && !$answered) {
            return self::ANSWER;
        }

        return self::NOTHING;
    }

    /** May this user pair a method right now, of their own accord? */
    public function allowsSetUp(bool $hasMethod): bool
    {
        return $this->isActive() && !$hasMethod;
    }
}
