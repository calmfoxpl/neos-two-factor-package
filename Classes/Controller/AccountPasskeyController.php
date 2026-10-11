<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Controller;

use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Passkey\PasskeyException;

/**
 * Adding a passkey from the user's own settings in the backend.
 *
 * Its own small controller rather than two actions of the settings module, because a Neos
 * module answers inside the backend's page frame, and the passkey script needs plain JSON.
 * Unlike the setup and the challenge, these addresses are behind the guard: only somebody who
 * has already answered their second factor in this sign-in may add another one.
 */
class AccountPasskeyController extends AbstractTwoFactorController
{
    public function optionsAction(): string
    {
        $account = $this->currentAccount->get();
        if (null === $account || !$this->mayAdd($account)) {
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

    public function registerAction(): string
    {
        $account = $this->currentAccount->get();
        if (null === $account || !$this->mayAdd($account)) {
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
        $this->flash('success.passkeyAdded');

        return $this->json(['reload' => true]);
    }

    /**
     * Past the guard, the policy switched on, and not while acting as somebody else: the key
     * would land on the impersonating administrator's account, which is not what the screen
     * in front of them suggests.
     */
    private function mayAdd(\Neos\Flow\Security\Account $account): bool
    {
        return Policy::NOTHING === $this->guard->next($account)
            && $this->twoFactorSettings->policy()->isActive()
            && !$this->currentAccount->isImpersonating();
    }
}
