<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Passkey;

/**
 * Something about a passkey ceremony did not add up. The message is a translation id for what
 * the person can do, never what failed cryptographically: a precise answer here would help
 * somebody probing.
 */
class PasskeyException extends \RuntimeException
{
    public static function challengeGone(): self
    {
        return new self('error.expired');
    }

    public static function rejected(?\Throwable $previous = null): self
    {
        return new self('error.passkeyRejected', 0, $previous);
    }

    public static function alreadyPaired(): self
    {
        return new self('error.passkeyAlreadyPaired');
    }

    public static function notAvailable(): self
    {
        return new self('error.passkeysOff');
    }

    public static function insecureOrigin(): self
    {
        return new self('error.passkeysNeedHttps');
    }

    /** The translation id of the message to show. */
    public function messageId(): string
    {
        return $this->getMessage();
    }
}
