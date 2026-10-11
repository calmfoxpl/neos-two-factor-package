<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Controller;

use Calmfox\TwoFactor\Service\AccountStatus;
use Calmfox\TwoFactor\Service\Management;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;

/**
 * Reset and turn off, posted from a user's page in Neos' own user management (see
 * Resources/Private/Layouts/NeosUsersModule), so an administrator does not have to go to a
 * second module to deal with a lost phone.
 *
 * A controller of its own with plain addresses, because the form sits inside a module that is
 * not ours: Neos would route a module action to its own users controller. The guard applies:
 * only somebody who has answered their own second factor gets here, and Policy.yaml grants it
 * to administrators only.
 */
class ManageController extends AbstractTwoFactorController
{
    /**
     * @Flow\Inject
     * @var Management
     */
    protected $management;

    /**
     * @Flow\Inject
     * @var AccountStatus
     */
    protected $accountStatus;

    /**
     * Its own name: Flow 8's controllers already have a $persistenceManager and Flow 9's do not.
     *
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistence;

    public function resetAction(string $account = '', string $returnTo = ''): void
    {
        $target = $this->target($account, $returnTo);
        $this->management->reset($target, $this->currentAccount->get());
        $this->addFlashMessage($this->translate('success.reset', [$target->getAccountIdentifier()]));

        $this->redirectToUri($this->back($returnTo));
    }

    public function turnOffAction(string $account = '', string $returnTo = ''): void
    {
        $target = $this->target($account, $returnTo);
        $this->management->turnOff($target);
        $this->addFlashMessage($this->translate('success.turnedOff', [$target->getAccountIdentifier()]));

        $this->redirectToUri($this->back($returnTo));
    }

    private function target(string $identifier, string $returnTo): Account
    {
        $account = '' !== $identifier ? $this->persistence->getObjectByIdentifier($identifier, Account::class) : null;
        if (!$account instanceof Account || !$this->accountStatus->applies($account)) {
            $this->addFlashMessage($this->translate('error.noSuchAccount'), '', Message::SEVERITY_ERROR);
            $this->redirectToUri($this->back($returnTo));
        }

        return $account;
    }

    /** Back to the page the form was on, as long as it is a page of this site. */
    private function back(string $returnTo): string
    {
        if ('' !== $returnTo && str_starts_with($returnTo, $this->baseUri())) {
            return $returnTo;
        }

        return $this->uriBuilder->reset()->setCreateAbsoluteUri(true)->uriFor('index', ['module' => 'administration/users'], 'Backend\Module', 'Neos.Neos');
    }
}
