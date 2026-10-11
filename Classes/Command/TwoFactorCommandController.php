<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Command;

use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Domain\Repository\TwoFactorRepository;
use Calmfox\TwoFactor\Service\Management;
use Calmfox\TwoFactor\Service\Settings;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Security\AccountRepository;

/**
 * The way back in when the backend is out of reach: a lost phone with nobody else to reset it,
 * or a policy switched on before anybody had paired anything.
 */
class TwoFactorCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var Management
     */
    protected $management;

    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $twoFactorSettings;

    /**
     * @Flow\Inject
     * @var AccountRepository
     */
    protected $accountRepository;

    /**
     * @Flow\Inject
     * @var TwoFactorRepository
     */
    protected $repository;

    /**
     * Reset a user's second factor
     *
     * Removes the authenticator app and every passkey paired with the account. By default a
     * new device has to be paired at the next sign-in, whatever the policy says, and the
     * account's open sessions end. With --disable the account is simply left without a second
     * factor, and the policy decides what happens next.
     *
     * @param string $username The user name the person signs in with
     * @param bool $disable Remove everything and leave the rest to the policy, instead of demanding a new pairing
     * @param string $provider The authentication provider of the account
     */
    public function resetCommand(string $username, bool $disable = false, string $provider = 'Neos.Neos:Backend'): void
    {
        // not only active accounts: an expired account may be about to be renewed
        $account = $this->accountRepository->findByAccountIdentifierAndAuthenticationProviderName($username, $provider);
        if (null === $account) {
            $this->outputLine('<error>There is no account "%s" for the provider "%s".</error>', [$username, $provider]);
            $this->quit(1);
        }

        if ($disable) {
            $this->management->turnOff($account);
            $this->outputLine('Two-step verification for "%s" has been turned off.', [$username]);

            return;
        }

        $this->management->reset($account);
        $this->outputLine('Two-step verification for "%s" has been reset. They will pair a new device at their next sign-in.', [$username]);
    }

    /**
     * Show or change who must use a second factor
     *
     * Without --policy, prints the policy in force. With it, changes it: required (every
     * backend user), optional (each user decides) or disabled (nobody is asked; anything paired
     * is kept for when it is switched back on). The value has to be named, as in
     * "./flow twofactor:policy --policy optional"; a bare "./flow twofactor:policy optional"
     * only prints the current policy.
     *
     * @param string $policy The new policy: required, optional or disabled
     */
    public function policyCommand(string $policy = ''): void
    {
        if ('' === $policy) {
            $this->outputLine($this->twoFactorSettings->policy()->name());

            return;
        }
        if (!\in_array($policy, Policy::ALL, true)) {
            $this->outputLine('<error>Unknown policy "%s". Use one of: %s.</error>', [$policy, implode(', ', Policy::ALL)]);
            $this->quit(1);
        }

        $this->twoFactorSettings->changePolicy(new Policy($policy));
        $this->outputLine('The two-factor policy is now "%s".', [$policy]);
    }

    /**
     * List who has paired what
     *
     * One line per account that has anything paired or a pairing due. Accounts that are not
     * listed have nothing paired.
     */
    public function listCommand(): void
    {
        $rows = [];
        foreach ($this->repository->findAll() as $record) {
            $account = $record->getAccount();
            $rows[] = [
                $account->getAccountIdentifier(),
                $account->getAuthenticationProviderName(),
                $record->hasAuthenticatorApp() ? 'yes' : 'no',
                (string) $record->getPasskeys()->count(),
                $record->isSetupRequired() ? 'yes' : 'no',
            ];
        }

        $this->outputLine('Policy: %s', [$this->twoFactorSettings->policy()->name()]);
        if ([] === $rows) {
            $this->outputLine('Nobody has paired a second factor yet.');

            return;
        }
        $this->output->outputTable($rows, ['Account', 'Provider', 'App', 'Passkeys', 'Pairing due']);
    }
}
