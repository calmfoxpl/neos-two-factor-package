<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Calmfox\TwoFactor\Core\Totp\Secret;
use Calmfox\TwoFactor\Core\Totp\Totp;
use Calmfox\TwoFactor\Domain\Model\TwoFactor;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * The authenticator-app side. The secret is kept sealed and opened only for the moment a code
 * is checked; it is never handed to a caller.
 *
 * @Flow\Scope("singleton")
 */
class Authenticator
{
    /**
     * @Flow\Inject
     * @var SecretKey
     */
    protected $secretKey;

    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $settings;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    public function newSecret(): string
    {
        return Secret::generate();
    }

    /** What the QR code encodes, and what an app needs if it is typed in by hand. */
    public function setupUri(string $secret, string $account, string $host): string
    {
        return Totp::uri($secret, $account, $this->settings->totpIssuer($host));
    }

    public function readableSecret(string $secret): string
    {
        return Secret::readable($secret);
    }

    /**
     * Whether the paired app can actually be checked. A secret sealed under an older Flow
     * encryption key cannot, and a user must not be asked for a code nobody can verify.
     */
    public function isUsable(TwoFactor $record): bool
    {
        $sealed = $record->getSealedTotpSecret();
        if (null === $sealed || '' === $sealed) {
            return false;
        }
        $secret = $this->secretKey->open($sealed);

        return null !== $secret && Secret::isWellFormed($secret);
    }

    /**
     * Checks a typed code against a secret that is not stored yet: the pairing step.
     *
     * Returns the step the code belonged to, so the caller stores it with the secret.
     * Otherwise the code that paired the app would still be good for the first challenge.
     */
    public function matchNewSecret(string $secret, string $code, ?int $now = null): ?int
    {
        if (!Secret::isWellFormed($secret)) {
            return null;
        }

        return Totp::match($secret, $code, $now ?? time(), $this->settings->totpWindow());
    }

    public function pair(TwoFactor $record, string $secret, int $step): void
    {
        $record->pairAuthenticatorApp($this->secretKey->seal($secret), $step);
    }

    /**
     * Checks a typed code against the account's secret, and spends it.
     *
     * A right code is accepted once: its step is claimed, and a code from that step or an
     * earlier one is refused from then on, however right the arithmetic is.
     */
    public function verify(TwoFactor $record, string $code, ?int $now = null): bool
    {
        $sealed = $record->getSealedTotpSecret();
        if (null === $sealed || $this->persistenceManager->isNewObject($record)) {
            return false;
        }

        $secret = $this->secretKey->open($sealed);
        if (null === $secret || !Secret::isWellFormed($secret)) {
            return false;
        }

        $step = Totp::match($secret, $code, $now ?? time(), $this->settings->totpWindow());

        return null !== $step && $this->claim($record, $step);
    }

    /**
     * Spends a step on behalf of this account.
     *
     * One conditional UPDATE rather than a read followed by a write. Two requests carrying the
     * same code at the same instant would both pass a read-then-write, each seeing the old
     * step; here the database decides and exactly one of them changes the row.
     */
    private function claim(TwoFactor $record, int $step): bool
    {
        $affected = $this->entityManager->createQuery(
            'UPDATE ' . TwoFactor::class . ' r SET r.lastTotpStep = :step'
            . ' WHERE r = :record AND (r.lastTotpStep IS NULL OR r.lastTotpStep < :step)',
        )
            ->setParameter('step', $step)
            ->setParameter('record', $record)
            ->execute();

        return 1 === (int) $affected;
    }
}
