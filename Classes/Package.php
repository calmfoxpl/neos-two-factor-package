<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor;

use Calmfox\TwoFactor\Service\TwoFactorSession;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Flow\Security\Authentication\AuthenticationProviderManager;

class Package extends BasePackage
{
    /**
     * A password that has just been checked starts a new sign-in, and whatever was answered in
     * the session before belongs to the old one. Signing out needs nothing of ours: Flow destroys
     * the whole session.
     */
    public function boot(Bootstrap $bootstrap): void
    {
        $bootstrap->getSignalSlotDispatcher()->connect(
            AuthenticationProviderManager::class,
            'authenticatedToken',
            TwoFactorSession::class,
            'onAuthenticatedToken',
        );
    }
}
