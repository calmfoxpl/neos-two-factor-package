<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Session\SessionInterface;
use Neos\Flow\Session\SessionManagerInterface;

/**
 * What this sign-in has already proved, kept in the Flow session under one key of our own.
 *
 * The answer is tied to the account that gave it, so a session that changes hands — another
 * account signing in on the same browser — never inherits it. A fresh password also wipes it
 * (see Package::boot), and signing out destroys the whole session anyway.
 *
 * @Flow\Scope("singleton")
 */
class TwoFactorSession
{
    private const KEY = 'Calmfox_TwoFactor';

    /** Seconds a half-finished app pairing is kept: long enough to find the phone, not longer. */
    private const PENDING_SECRET_LIFETIME = 900;

    /**
     * @Flow\Inject
     * @var SessionManagerInterface
     */
    protected $sessionManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * The second factor was answered. The session gets a new id as well: the session now
     * carries more privilege than it did a moment ago, so any id somebody may have learned
     * before this point must stop working.
     */
    public function answer(Account $account): void
    {
        $session = $this->session();
        if (null === $session) {
            return;
        }

        $data = $this->data();
        $data['answeredBy'] = $this->identifier($account);
        unset($data['failures'], $data['challenge']);
        $this->write($data);
        $session->renewId();
    }

    public function isAnswered(Account $account): bool
    {
        $answeredBy = $this->data()['answeredBy'] ?? null;

        return \is_string($answeredBy) && hash_equals($answeredBy, $this->identifier($account));
    }

    /** Called when a password was checked: whatever was answered before belongs to an older sign-in. */
    public function onAuthenticatedToken(TokenInterface $token): void
    {
        $this->forget();
    }

    public function forget(): void
    {
        if (null !== $this->session()) {
            $this->write([]);
        }
    }

    /** @return int how many wrong answers this sign-in has given so far, this one included */
    public function recordFailure(): int
    {
        $data = $this->data();
        $data['failures'] = (int) ($data['failures'] ?? 0) + 1;
        $this->write($data);

        return $data['failures'];
    }

    /** A WebAuthn challenge is used once: a failed attempt burns it too. */
    public function rememberPasskeyChallenge(string $challenge): void
    {
        $data = $this->data();
        $data['challenge'] = base64_encode($challenge);
        $this->write($data);
    }

    public function takePasskeyChallenge(): ?string
    {
        $data = $this->data();
        $stored = $data['challenge'] ?? null;
        unset($data['challenge']);
        $this->write($data);

        if (!\is_string($stored) || '' === $stored) {
            return null;
        }
        $binary = base64_decode($stored, true);

        return false === $binary || '' === $binary ? null : $binary;
    }

    /**
     * The secret of an app being paired right now. It waits here, not on the account, until a
     * code it produced has been typed, so an abandoned pairing leaves nothing behind and nobody
     * is locked out by a secret their app never received.
     */
    public function pendingSecret(Account $account, callable $generate): string
    {
        $data = $this->data();
        $pending = $data['pendingSecret'] ?? null;
        $fresh = \is_array($pending)
            && ($pending['account'] ?? null) === $this->identifier($account)
            && \is_string($pending['secret'] ?? null)
            && (int) ($pending['created'] ?? 0) > time() - self::PENDING_SECRET_LIFETIME;

        if ($fresh) {
            return $pending['secret'];
        }

        $secret = (string) $generate();
        $data['pendingSecret'] = ['account' => $this->identifier($account), 'secret' => $secret, 'created' => time()];
        $this->write($data);

        return $secret;
    }

    /** The pending secret if it is still fresh, without making a new one. */
    public function takePendingSecret(Account $account): ?string
    {
        $data = $this->data();
        $pending = $data['pendingSecret'] ?? null;
        $valid = \is_array($pending)
            && ($pending['account'] ?? null) === $this->identifier($account)
            && \is_string($pending['secret'] ?? null)
            && (int) ($pending['created'] ?? 0) > time() - self::PENDING_SECRET_LIFETIME;

        return $valid ? $pending['secret'] : null;
    }

    public function forgetPendingSecret(): void
    {
        $data = $this->data();
        unset($data['pendingSecret']);
        $this->write($data);
    }

    /** Where the user was going when the guard stopped them, so we can take them there after. */
    public function rememberDestination(string $uri): void
    {
        $data = $this->data();
        $data['destination'] = $uri;
        $this->write($data);
    }

    public function takeDestination(): ?string
    {
        $data = $this->data();
        $destination = $data['destination'] ?? null;
        unset($data['destination']);
        $this->write($data);

        return \is_string($destination) && '' !== $destination ? $destination : null;
    }

    private function identifier(Account $account): string
    {
        return (string) $this->persistenceManager->getIdentifierByObject($account);
    }

    private function session(): ?SessionInterface
    {
        $session = $this->sessionManager->getCurrentSession();

        return $session->isStarted() ? $session : null;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $session = $this->session();
        if (null === $session || !$session->hasKey(self::KEY)) {
            return [];
        }
        $data = $session->getData(self::KEY);

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $this->session()?->putData(self::KEY, $data);
    }
}
