<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Domain\Repository;

use Calmfox\TwoFactor\Domain\Model\TwoFactor;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;
use Neos\Flow\Security\Account;

/**
 * @Flow\Scope("singleton")
 *
 * @method TwoFactor|null findOneByAccount(Account $account)
 */
class TwoFactorRepository extends Repository
{
    /** The account's record, or a new empty one that is only stored once something is paired. */
    public function forAccount(Account $account): TwoFactor
    {
        return $this->findOneByAccount($account) ?? new TwoFactor($account);
    }

    public function save(TwoFactor $record): void
    {
        if ($this->persistenceManager->isNewObject($record)) {
            $this->add($record);
        } else {
            $this->update($record);
        }
        $this->persistenceManager->persistAll();
    }

    /**
     * Every record, keyed by the account's persistence identifier, for the overview in the
     * backend: one query instead of one per user.
     *
     * @return array<string, TwoFactor>
     */
    public function allByAccount(): array
    {
        $records = [];
        foreach ($this->findAll() as $record) {
            $identifier = $this->persistenceManager->getIdentifierByObject($record->getAccount());
            if (\is_string($identifier)) {
                $records[$identifier] = $record;
            }
        }

        return $records;
    }
}
