<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Calmfox\TwoFactor\Domain\Repository\TwoFactorRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Session\SessionManagerInterface;

/**
 * What an administrator can do to somebody else's second factor, from the backend or the
 * command line, in one place so that both do exactly the same.
 *
 * @Flow\Scope("singleton")
 */
class Management
{
    /**
     * @Flow\Inject
     * @var TwoFactorRepository
     */
    protected $repository;

    /**
     * @Flow\Inject
     * @var SessionManagerInterface
     */
    protected $sessionManager;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * For a lost or replaced phone: everything paired is removed and a new device has to be
     * paired at the next sign-in, whatever the policy says.
     *
     * The account's other sessions end as well. A phone that went missing may have gone missing
     * together with a laptop that is still signed in, and "pair a new device at the next
     * sign-in" means little if the next sign-in is weeks away.
     */
    public function reset(Account $account, ?Account $actingAccount = null): void
    {
        $record = $this->repository->forAccount($account);
        $record->clear(true);
        $this->repository->save($record);

        if (!$this->isSameAccount($account, $actingAccount)) {
            $this->sessionManager->destroySessionsByTag(
                $this->securityContext->getSessionTagForAccount($account),
                'Two-step verification was reset by an administrator',
            );
        }
    }

    /** Everything paired is removed, and the policy decides what happens next. */
    public function turnOff(Account $account): void
    {
        $record = $this->repository->findOneByAccount($account);
        if (null === $record) {
            return;
        }
        $record->clear(false);
        $this->repository->save($record);
    }

    private function isSameAccount(Account $account, ?Account $other): bool
    {
        return null !== $other
            && $this->persistenceManager->getIdentifierByObject($account) === $this->persistenceManager->getIdentifierByObject($other);
    }
}
