<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Controller\Module;

use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Domain\Model\TwoFactor;
use Calmfox\TwoFactor\Domain\Repository\TwoFactorRepository;
use Calmfox\TwoFactor\Service\AccountStatus;
use Calmfox\TwoFactor\Service\Assets;
use Calmfox\TwoFactor\Service\CurrentAccount;
use Calmfox\TwoFactor\Service\Management;
use Calmfox\TwoFactor\Service\Settings;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;

/**
 * Administration → Two-Factor Authentication: the policy, and who is covered.
 *
 * Every backend account is listed with what it has paired, so "is everybody covered?" is one
 * look rather than a question to each colleague. Reset and turn off act on one account at a
 * time and say what they will do before they do it.
 */
class UsersController extends AbstractModuleController
{
    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $twoFactorSettings;

    /**
     * @Flow\Inject
     * @var TwoFactorRepository
     */
    protected $repository;

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
     * @Flow\Inject
     * @var CurrentAccount
     */
    protected $currentAccount;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var Translator
     */
    protected $translator;

    /**
     * @Flow\Inject
     * @var Assets
     */
    protected $assets;

    /**
     * Our stylesheet and script with a content fingerprint in the address (see Assets). Loaded
     * from the template rather than the module's additionalResources, which cannot carry one.
     */
    protected function initializeView(\Neos\Flow\Mvc\View\ViewInterface $view)
    {
        parent::initializeView($view);
        $view->assignMultiple([
            'moduleStylesheetUri' => $this->assets->uri('Styles/Module.css'),
            'passkeyScriptUri' => $this->assets->uri('Scripts/Passkey.js'),
        ]);
    }

    public function indexAction(): void
    {
        $records = $this->repository->allByAccount();
        $providers = $this->twoFactorSettings->authenticationProviders();
        $me = $this->currentAccount->get();
        $myIdentifier = null !== $me ? $this->persistenceManager->getIdentifierByObject($me) : null;

        $rows = [];
        foreach ($this->userService->getUsers() as $user) {
            if (!$user instanceof User) {
                continue;
            }
            foreach ($user->getAccounts() as $account) {
                if (!$account instanceof Account || !\in_array($account->getAuthenticationProviderName(), $providers, true)) {
                    continue;
                }
                $identifier = (string) $this->persistenceManager->getIdentifierByObject($account);
                $rows[] = $this->row($user, $account, $records[$identifier] ?? null, $identifier === $myIdentifier);
            }
        }

        $policy = $this->twoFactorSettings->policy();
        $this->view->assignMultiple([
            'total' => \count($rows),
            'policy' => $policy->name(),
            'policies' => Policy::ALL,
            'active' => $policy->isActive(),
            'rows' => $rows,
            'uncovered' => \count(array_filter($rows, static fn (array $row): bool => !$row['covered'])),
        ]);
    }

    public function updatePolicyAction(string $policy = ''): void
    {
        if (!\in_array($policy, Policy::ALL, true)) {
            $this->redirect('index');
        }

        $this->twoFactorSettings->changePolicy(new Policy($policy));
        $this->flash('success.policyChanged');
        $this->redirect('index');
    }

    public function resetAction(string $account = ''): void
    {
        $target = $this->account($account);
        $this->management->reset($target, $this->currentAccount->get());

        $this->flash('success.reset', [$target->getAccountIdentifier()]);
        $this->redirect('index');
    }

    public function turnOffAction(string $account = ''): void
    {
        $target = $this->account($account);
        $this->management->turnOff($target);

        $this->flash('success.turnedOff', [$target->getAccountIdentifier()]);
        $this->redirect('index');
    }

    /** @return array<string, mixed> */
    private function row(User $user, Account $account, ?TwoFactor $record, bool $isMe): array
    {
        return $this->accountStatus->of($account, $record) + [
            'name' => (string) $user->getLabel(),
            'initials' => self::initials((string) $user->getLabel(), $account->getAccountIdentifier()),
            'isMe' => $isMe,
        ];
    }

    /** Two letters for the avatar circle: first and last name, or the start of the user name. */
    private static function initials(string $name, string $username): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = \count($words) >= 2
            ? mb_substr($words[0], 0, 1) . mb_substr($words[\count($words) - 1], 0, 1)
            : mb_substr('' !== trim($name) ? trim($name) : $username, 0, 2);

        return mb_strtoupper($letters);
    }

    private function account(string $identifier): Account
    {
        $account = '' !== $identifier ? $this->persistenceManager->getObjectByIdentifier($identifier, Account::class) : null;
        if (!$account instanceof Account || !\in_array($account->getAuthenticationProviderName(), $this->twoFactorSettings->authenticationProviders(), true)) {
            $this->flash('error.noSuchAccount', [], Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        return $account;
    }

    /** @param array<int, string> $arguments */
    private function flash(string $id, array $arguments = [], string $severity = Message::SEVERITY_OK): void
    {
        $this->addFlashMessage($this->translate($id, $arguments), '', $severity);
    }

    /** @param array<int, string> $arguments */
    private function translate(string $id, array $arguments = []): string
    {
        return (string) $this->translator->translateById($id, $arguments, null, null, 'Main', 'Calmfox.TwoFactor');
    }
}
