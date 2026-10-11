<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Domain\Model\TwoFactor;
use Calmfox\TwoFactor\Domain\Repository\TwoFactorRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;

/**
 * What still has to happen before this account may use the backend: pairing a second factor,
 * answering one, or nothing. The middleware, the controllers and the templates all ask here.
 *
 * @Flow\Scope("singleton")
 */
class Guard
{
    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $settings;

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
    protected $session;

    /** @return Policy::NOTHING|Policy::SET_UP|Policy::ANSWER */
    public function next(Account $account): string
    {
        $record = $this->repository->forAccount($account);

        return $this->settings->policy()->next(
            $this->hasMethod($record),
            $record->isSetupRequired(),
            $this->session->isAnswered($account),
        );
    }

    public function record(Account $account): TwoFactor
    {
        return $this->repository->forAccount($account);
    }

    /** A method counts only if it can be answered today. */
    public function hasMethod(TwoFactor $record): bool
    {
        return $this->canUseApp($record) || $this->canUsePasskeys($record);
    }

    public function canUseApp(TwoFactor $record): bool
    {
        return $this->authenticator->isUsable($record);
    }

    /** Passkeys switched off in the settings cannot be asked for, so they do not count. */
    public function canUsePasskeys(TwoFactor $record): bool
    {
        return $this->settings->passkeysEnabled() && !$record->getPasskeys()->isEmpty();
    }
}
