<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Domain\Model;

use Calmfox\TwoFactor\Core\Passkey\CredentialSet;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;

/**
 * What one backend account has paired: an authenticator app, passkeys, or neither, and whether
 * somebody has asked for a new pairing.
 *
 * It hangs on the Flow account rather than on the Neos user, because the account is what signs
 * in: a user with two accounts under two providers is two sign-ins. Deleting the account in
 * Neos deletes this row with it, at the database level.
 *
 * @Flow\Entity
 * @ORM\Table(name="calmfox_twofactor")
 */
class TwoFactor
{
    /**
     * @ORM\OneToOne
     * @ORM\JoinColumn(nullable=false, unique=true, onDelete="CASCADE")
     * @var Account
     */
    protected $account;

    /**
     * The authenticator-app secret, sealed (see Service\SecretKey). Null when no app is paired.
     *
     * @ORM\Column(type="text", nullable=true)
     * @var string|null
     */
    protected $totpSecret = null;

    /**
     * The newest 30-second step whose code was accepted, so the same code cannot be used twice.
     * An integer is enough until the year 6000.
     *
     * @ORM\Column(type="integer", nullable=true)
     * @var int|null
     */
    protected $lastTotpStep = null;

    /**
     * The paired passkeys as JSON: ids, names, public keys and counters, never a private key.
     *
     * A text column encoded here rather than Doctrine's json type: Flow validates entity
     * properties by their declared type before saving, and would refuse a plain array as "not
     * a collection". A string it leaves alone, and the column is the same on every database.
     *
     * @ORM\Column(type="text")
     * @var string
     */
    protected $passkeys = '[]';

    /**
     * A colleague reset this account's second factor: a new one has to be paired at the next
     * sign-in, whatever the policy says.
     *
     * @ORM\Column(type="boolean")
     * @var bool
     */
    protected $setupRequired = false;

    public function __construct(Account $account)
    {
        $this->account = $account;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getSealedTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function hasAuthenticatorApp(): bool
    {
        return null !== $this->totpSecret && '' !== $this->totpSecret;
    }

    /** Stores a newly paired app, spending the step whose code proved the pairing. */
    public function pairAuthenticatorApp(string $sealedSecret, int $step): void
    {
        $this->totpSecret = $sealedSecret;
        $this->lastTotpStep = $step;
    }

    public function removeAuthenticatorApp(): void
    {
        $this->totpSecret = null;
        $this->lastTotpStep = null;
    }

    public function getPasskeys(): CredentialSet
    {
        $rows = json_decode($this->passkeys, true);

        return CredentialSet::fromArray(\is_array($rows) ? array_values($rows) : []);
    }

    public function setPasskeys(CredentialSet $passkeys): void
    {
        $this->passkeys = json_encode($passkeys->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    public function isSetupRequired(): bool
    {
        return $this->setupRequired;
    }

    public function setSetupRequired(bool $setupRequired): void
    {
        $this->setupRequired = $setupRequired;
    }

    /** Everything paired is removed; whether a new pairing is due is the caller's decision. */
    public function clear(bool $setupRequired): void
    {
        $this->removeAuthenticatorApp();
        $this->passkeys = '[]';
        $this->setupRequired = $setupRequired;
    }
}
