<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Controller\Module;

use Calmfox\TwoFactor\Core\Http\Paths;
use Calmfox\TwoFactor\Core\Passkey\Credential;
use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Domain\Repository\TwoFactorRepository;
use Calmfox\TwoFactor\Passkey\Ceremony;
use Calmfox\TwoFactor\Service\Authenticator;
use Calmfox\TwoFactor\Service\Assets;
use Calmfox\TwoFactor\Service\CurrentAccount;
use Calmfox\TwoFactor\Service\Guard;
use Calmfox\TwoFactor\Service\QrCode;
use Calmfox\TwoFactor\Service\Settings;
use Calmfox\TwoFactor\Service\TwoFactorSession;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Security\Account;
use Neos\Neos\Controller\Module\AbstractModuleController;

/**
 * The user's own second factor, under User → Two-Factor Authentication: which methods are
 * paired, adding a passkey or an app, removing one.
 */
class AccountController extends AbstractModuleController
{
    /**
     * @Flow\Inject
     * @var CurrentAccount
     */
    protected $currentAccount;

    /**
     * @Flow\Inject
     * @var Guard
     */
    protected $guard;

    /**
     * @Flow\Inject
     * @var TwoFactorRepository
     */
    protected $repository;

    /**
     * @Flow\Inject
     * @var Authenticator
     */
    protected $authenticator;

    /**
     * @Flow\Inject
     * @var TwoFactorSession
     */
    protected $twoFactorSession;

    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $twoFactorSettings;

    /**
     * @Flow\Inject
     * @var Ceremony
     */
    protected $ceremony;

    /**
     * @Flow\Inject
     * @var QrCode
     */
    protected $qrCode;

    /**
     * @Flow\Inject
     * @var BaseUriProvider
     */
    protected $baseUriProvider;

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
        $account = $this->currentAccount->get();
        if (null === $account || $this->currentAccount->isImpersonating()) {
            $this->view->assign('unavailable', true);

            return;
        }

        $record = $this->guard->record($account);
        $policy = $this->twoFactorSettings->policy();
        $hasApp = $this->guard->canUseApp($record);
        $httpRequest = $this->request->getHttpRequest();

        $pairing = [];
        if ($policy->isActive() && !$hasApp) {
            $secret = $this->twoFactorSession->pendingSecret($account, fn (): string => $this->authenticator->newSecret());
            $pairing = [
                'qrCode' => $this->qrCode->dataUri($this->authenticator->setupUri($secret, $account->getAccountIdentifier(), $this->host())),
                'readableSecret' => $this->authenticator->readableSecret($secret),
                'accountLabel' => $account->getAccountIdentifier(),
            ];
        }

        $this->view->assignMultiple([
            'unavailable' => false,
            'policy' => $policy->name(),
            'active' => $policy->isActive(),
            'hasApp' => $hasApp,
            // a sealed secret this installation can no longer open: shown so it can be replaced
            'unreadableApp' => $record->hasAuthenticatorApp() && !$hasApp,
            'passkeys' => array_map(static fn (Credential $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'createdAt' => self::date($passkey->createdAt),
                'lastUsedAt' => self::date($passkey->lastUsedAt),
            ], $record->getPasskeys()->all()),
            'passkeysEnabled' => $this->twoFactorSettings->passkeysEnabled(),
            'passkeysAvailable' => $this->ceremony->isAvailable($httpRequest),
            'pairing' => $pairing,
            'passkeyOptionsUri' => $this->baseUri() . Paths::path(Paths::ACCOUNT_PASSKEY_OPTIONS),
            'passkeyUri' => $this->baseUri() . Paths::path(Paths::ACCOUNT_PASSKEY),
            'confirmRemovePasskey' => $this->translate('account.passkeys.removeConfirm'),
            'confirmRemoveApp' => $this->translate('account.app.removeConfirm'),
        ]);
    }

    public function pairAppAction(string $code = ''): void
    {
        $account = $this->accountOrLeave();
        if (!$this->twoFactorSettings->policy()->isActive()) {
            $this->redirect('index');
        }

        $secret = $this->twoFactorSession->takePendingSecret($account);
        if (null === $secret) {
            $this->flash('error.setupExpired', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $step = $this->authenticator->matchNewSecret($secret, $code);
        if (null === $step) {
            $this->flash('error.wrongCode', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $record = $this->guard->record($account);
        $this->authenticator->pair($record, $secret, $step);
        $record->setSetupRequired(false);
        $this->repository->save($record);
        $this->twoFactorSession->forgetPendingSecret();

        $this->flash('success.appPaired');
        $this->redirect('index');
    }

    public function removeAppAction(): void
    {
        $account = $this->accountOrLeave();
        $record = $this->guard->record($account);

        $remaining = $this->guard->canUsePasskeys($record);
        if (!$this->mayRemove($remaining)) {
            $this->flash('error.lastMethod', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $record->removeAuthenticatorApp();
        $this->repository->save($record);

        $this->flash('success.appRemoved');
        $this->redirect('index');
    }

    public function removePasskeyAction(string $passkey = ''): void
    {
        $account = $this->accountOrLeave();
        $record = $this->guard->record($account);
        $passkeys = $record->getPasskeys();
        if (!$passkeys->has($passkey)) {
            $this->redirect('index');
        }

        $remaining = $this->guard->canUseApp($record)
            || ($this->twoFactorSettings->passkeysEnabled() && $passkeys->count() > 1);
        if (!$this->mayRemove($remaining)) {
            $this->flash('error.lastMethod', Message::SEVERITY_ERROR);
            $this->redirect('index');
        }

        $record->setPasskeys($passkeys->remove($passkey));
        $this->repository->save($record);

        $this->flash('success.passkeyRemoved');
        $this->redirect('index');
    }

    /**
     * Under a required policy the last method cannot be removed from here: the next request
     * would send the user straight to the setup page, which is a confusing way to learn that.
     * Adding the new method first and then removing the old one works, and says what it does.
     */
    private function mayRemove(bool $somethingRemains): bool
    {
        return $somethingRemains || Policy::REQUIRED !== $this->twoFactorSettings->policy()->name();
    }

    private function accountOrLeave(): Account
    {
        $account = $this->currentAccount->get();
        if (null === $account || $this->currentAccount->isImpersonating()) {
            $this->redirect('index');
        }

        return $account;
    }

    private function flash(string $id, string $severity = Message::SEVERITY_OK): void
    {
        $this->addFlashMessage($this->translate($id), '', $severity);
    }

    private function translate(string $id): string
    {
        return (string) $this->translator->translateById($id, [], null, null, 'Main', 'Calmfox.TwoFactor');
    }

    /**
     * The same base Neos' own links are built on: the configured `Neos.Flow.http.baseUri`, or
     * the request as Flow's trusted-proxies handling rewrote it, so a site behind a proxy or
     * with a configured base URI is not sent to another host, port or scheme.
     */
    private function baseUri(): string
    {
        return rtrim((string) $this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest($this->request->getHttpRequest()), '/') . '/';
    }

    private function host(): string
    {
        return strtolower($this->request->getHttpRequest()->getUri()->getHost());
    }

    private static function date(?string $atom): ?\DateTimeImmutable
    {
        if (null === $atom || '' === $atom) {
            return null;
        }

        try {
            // stored in UTC, shown in the server's time zone like the rest of the backend
            return (new \DateTimeImmutable($atom))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
