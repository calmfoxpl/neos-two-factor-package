<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Calmfox\TwoFactor\Domain\Model\TwoFactor;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;

/**
 * What an administrator sees about one account's second factor, the same in our overview and
 * on the user's page in Neos' own user management, so the two can never disagree.
 *
 * @Flow\Scope("singleton")
 */
class AccountStatus
{
    /**
     * @Flow\Inject
     * @var Guard
     */
    protected $guard;

    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $settings;

    /**
     * @Flow\Inject
     * @var Translator
     */
    protected $translator;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /** Whether the second factor applies to this account at all. */
    public function applies(Account $account): bool
    {
        return \in_array($account->getAuthenticationProviderName(), $this->settings->authenticationProviders(), true);
    }

    /** @return array<string, mixed> */
    public function of(Account $account, ?TwoFactor $record): array
    {
        $hasApp = null !== $record && $this->guard->canUseApp($record);
        $passkeys = null !== $record ? $record->getPasskeys()->count() : 0;
        $setupRequired = null !== $record && $record->isSetupRequired();
        $username = $account->getAccountIdentifier();

        return [
            'identifier' => (string) $this->persistenceManager->getIdentifierByObject($account),
            'username' => $username,
            'provider' => $account->getAuthenticationProviderName(),
            'hasApp' => $hasApp,
            'passkeys' => $passkeys,
            'setupRequired' => $setupRequired,
            'covered' => $hasApp || ($passkeys > 0 && $this->settings->passkeysEnabled()),
            'hasAnything' => null !== $record && ($record->hasAuthenticatorApp() || $passkeys > 0 || $setupRequired),
            'confirmReset' => $this->translate('users.resetConfirm', [$username]),
            'confirmTurnOff' => $this->translate('users.turnOffConfirm', [$username]),
        ];
    }

    /** @param array<int, string> $arguments */
    private function translate(string $id, array $arguments): string
    {
        return (string) $this->translator->translateById($id, $arguments, null, null, 'Main', 'Calmfox.TwoFactor');
    }
}
