<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Controller;

use Calmfox\TwoFactor\Core\Http\Paths;
use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Passkey\PasskeyException;
use Calmfox\TwoFactor\Service\Authenticator;
use Calmfox\TwoFactor\Service\QrCode;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;

/**
 * Pairing a second factor, for somebody who has to have one before they may go on: the policy
 * requires it, or a colleague reset theirs.
 *
 * A passkey is offered first, because it cannot be phished and there is nothing to retype. An
 * authenticator app, with a QR code or a key to type by hand, comes next.
 */
class SetupController extends AbstractTwoFactorController
{
    private const PASSKEY = 'passkey';

    private const APP = 'app';

    /**
     * @Flow\Inject
     * @var Authenticator
     */
    protected $authenticator;

    /**
     * @Flow\Inject
     * @var QrCode
     */
    protected $qrCode;

    /**
     * Two steps: first the choice between the two methods, then the one that was chosen. Both
     * methods on one page read as a form to fill in twice; a choice first says there is one
     * thing to do, and which one is recommended.
     *
     * The step is in the address (?method=passkey or ?method=app) rather than in a script, so
     * the browser's back button returns to the choice and the page works without JavaScript.
     */
    public function indexAction(string $method = ''): void
    {
        $account = $this->account();
        $this->continueUnless($account, Policy::SET_UP);

        $passkeysEnabled = $this->twoFactorSettings->passkeysEnabled();
        if (!$passkeysEnabled) {
            // with one method there is nothing to choose
            $method = self::APP;
        }
        if (!\in_array($method, [self::APP, self::PASSKEY], true)) {
            $method = '';
        }

        $view = $this->pageLook() + [
            'name' => $this->displayName($account),
            'method' => $method,
            'canChoose' => $passkeysEnabled,
            'passkeysAvailable' => $this->ceremony->isAvailable($this->request->getHttpRequest()),
            'choosePasskeyUri' => $this->pageUri(Paths::SETUP) . '?method=' . self::PASSKEY,
            'chooseAppUri' => $this->pageUri(Paths::SETUP) . '?method=' . self::APP,
            'chooseUri' => $this->pageUri(Paths::SETUP),
            'pairAppUri' => $this->pageUri(Paths::SETUP_APP),
            'passkeyOptionsUri' => $this->pageUri(Paths::SETUP_PASSKEY_OPTIONS),
            'passkeyUri' => $this->pageUri(Paths::SETUP_PASSKEY),
            'signOutUri' => $this->pageUri(Paths::SIGN_OUT),
        ];

        if (self::APP === $method) {
            // the secret is made only when somebody actually chose the app
            $secret = $this->twoFactorSession->pendingSecret($account, fn (): string => $this->authenticator->newSecret());
            $view += [
                'accountLabel' => $account->getAccountIdentifier(),
                'qrCode' => $this->qrCode->dataUri($this->authenticator->setupUri($secret, $account->getAccountIdentifier(), $this->host())),
                'readableSecret' => $this->authenticator->readableSecret($secret),
            ];
        }

        $this->view->assignMultiple($view);
    }

    /**
     * Finishes pairing an app. The secret only lands on the account once a code it produced
     * has been typed, so a pairing abandoned half way leaves nothing behind.
     */
    public function pairAppAction(string $code = ''): void
    {
        $account = $this->account();
        $this->continueUnless($account, Policy::SET_UP);

        $secret = $this->twoFactorSession->takePendingSecret($account);
        if (null === $secret) {
            $this->flash('error.setupExpired', Message::SEVERITY_ERROR);
            $this->redirectToUri($this->pageUri(Paths::SETUP) . '?method=' . self::APP);
        }

        $step = $this->authenticator->matchNewSecret($secret, $code);
        if (null === $step) {
            $this->flash('error.wrongCode', Message::SEVERITY_ERROR);
            $this->redirectToUri($this->pageUri(Paths::SETUP) . '?method=' . self::APP);
        }

        $record = $this->guard->record($account);
        $this->authenticator->pair($record, $secret, $step);
        $record->setSetupRequired(false);
        $this->repository->save($record);

        $this->twoFactorSession->forgetPendingSecret();
        // pairing proves what the challenge would; do not ask again in this sign-in
        $this->twoFactorSession->answer($account);
        $this->flash('success.appPaired');

        $this->redirectToUri($this->onwardUri());
    }

    public function passkeyOptionsAction(): string
    {
        $account = $this->currentAccount->get();
        if (null === $account || Policy::SET_UP !== $this->guard->next($account)) {
            return $this->json(['error' => $this->translate('error.expired')], 409);
        }

        try {
            return $this->json(['options' => $this->ceremony->registrationOptions(
                $account,
                $this->displayName($account),
                $this->guard->record($account),
                $this->request->getHttpRequest(),
            )]);
        } catch (PasskeyException $exception) {
            return $this->json(['error' => $this->translate($exception->messageId())], 400);
        }
    }

    public function passkeyRegisterAction(): string
    {
        $account = $this->currentAccount->get();
        if (null === $account || Policy::SET_UP !== $this->guard->next($account)) {
            return $this->json(['error' => $this->translate('error.expired')], 409);
        }

        $body = $this->body();
        $record = $this->guard->record($account);
        try {
            $record->setPasskeys($this->ceremony->register(
                $record,
                $this->request->getHttpRequest(),
                \is_array($body['credential'] ?? null) ? $body['credential'] : [],
                \is_string($body['name'] ?? null) ? $body['name'] : '',
            ));
        } catch (PasskeyException $exception) {
            return $this->json(['error' => $this->translate($exception->messageId())], 400);
        }

        $record->setSetupRequired(false);
        $this->repository->save($record);
        $this->twoFactorSession->forgetPendingSecret();
        $this->twoFactorSession->answer($account);
        $this->flash('success.passkeyPaired');

        return $this->json(['redirect' => $this->onwardUri()]);
    }
}
