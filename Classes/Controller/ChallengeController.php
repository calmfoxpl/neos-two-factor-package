<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Controller;

use Calmfox\TwoFactor\Core\Http\Paths;
use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Passkey\PasskeyException;
use Calmfox\TwoFactor\Service\Authenticator;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;

/**
 * The step after the password: a passkey or six digits from the app.
 */
class ChallengeController extends AbstractTwoFactorController
{
    /**
     * @Flow\Inject
     * @var Authenticator
     */
    protected $authenticator;

    public function indexAction(): void
    {
        $account = $this->account();
        $this->continueUnless($account, Policy::ANSWER);

        $record = $this->guard->record($account);
        $passkeys = $this->guard->canUsePasskeys($record) && $this->ceremony->isAvailable($this->request->getHttpRequest());

        $this->view->assignMultiple($this->pageLook() + [
            'name' => $this->displayName($account),
            'hasApp' => $this->guard->canUseApp($record),
            'hasPasskeys' => $passkeys,
            'verifyUri' => $this->pageUri(Paths::CHALLENGE),
            'passkeyOptionsUri' => $this->pageUri(Paths::CHALLENGE_PASSKEY_OPTIONS),
            'passkeyUri' => $this->pageUri(Paths::CHALLENGE_PASSKEY),
            'signOutUri' => $this->pageUri(Paths::SIGN_OUT),
        ]);
    }

    /**
     * A typed code. Wrong codes are counted, and the last allowed one ends the session: six
     * digits are a small space, and a password that has to be typed again for every few guesses
     * makes guessing them pointless.
     */
    public function verifyAction(string $code = ''): void
    {
        $account = $this->account();
        $this->continueUnless($account, Policy::ANSWER);

        $record = $this->guard->record($account);
        if ($this->guard->canUseApp($record) && $this->authenticator->verify($record, $code)) {
            $this->twoFactorSession->answer($account);
            $this->redirectToUri($this->onwardUri());
        }

        if ($this->twoFactorSession->recordFailure() >= $this->twoFactorSettings->maximumAttempts()) {
            // translated while the user, and so their language, is still known
            $message = $this->translate('error.tooManyAttempts');
            $this->authenticationManager->logout();
            $this->addFlashMessage($message, '', Message::SEVERITY_ERROR);
            $this->redirect('index', 'Login', 'Neos.Neos');
        }

        $this->flash('error.wrongCode', Message::SEVERITY_ERROR);
        $this->goTo(Paths::CHALLENGE);
    }

    public function passkeyOptionsAction(): string
    {
        $account = $this->currentAccount->get();
        if (null === $account || Policy::ANSWER !== $this->guard->next($account)) {
            return $this->json(['error' => $this->translate('error.expired')], 409);
        }

        try {
            return $this->json(['options' => $this->ceremony->loginOptions($this->guard->record($account), $this->request->getHttpRequest())]);
        } catch (PasskeyException $exception) {
            return $this->json(['error' => $this->translate($exception->messageId())], 400);
        }
    }

    public function passkeyVerifyAction(): string
    {
        $account = $this->currentAccount->get();
        if (null === $account || Policy::ANSWER !== $this->guard->next($account)) {
            return $this->json(['error' => $this->translate('error.expired')], 409);
        }

        $body = $this->body();
        $record = $this->guard->record($account);
        try {
            $record->setPasskeys($this->ceremony->verify(
                $account,
                $record,
                $this->request->getHttpRequest(),
                \is_array($body['credential'] ?? null) ? $body['credential'] : [],
            ));
        } catch (PasskeyException $exception) {
            if ($this->twoFactorSession->recordFailure() >= $this->twoFactorSettings->maximumAttempts()) {
                $message = $this->translate('error.tooManyAttempts');
                $this->authenticationManager->logout();

                return $this->json(['error' => $message, 'redirect' => $this->loginUri()], 403);
            }

            return $this->json(['error' => $this->translate($exception->messageId())], 400);
        }

        // the counter and the date of last use; the answer itself does not depend on this write
        $this->repository->save($record);
        $this->twoFactorSession->answer($account);

        return $this->json(['redirect' => $this->onwardUri()]);
    }

    /** The way out for somebody who cannot answer: back to the password, as somebody else if need be. */
    public function signOutAction(): void
    {
        if ($this->authenticationManager->isAuthenticated()) {
            $this->authenticationManager->logout();
        }
        $this->redirectToUri($this->loginUri());
    }

    private function loginUri(): string
    {
        return $this->uriBuilder->reset()->setCreateAbsoluteUri(true)->uriFor('index', [], 'Login', 'Neos.Neos');
    }
}
