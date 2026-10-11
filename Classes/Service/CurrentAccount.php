<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Service\ImpersonateService;

/**
 * The account of the person at the keyboard, if they signed in through one of the providers the
 * second factor applies to.
 *
 * "The person at the keyboard" matters when an administrator impersonates somebody: the
 * security context then holds the other user's account, but the one who typed a password and
 * answered a second factor is still the administrator. Asking the impersonated user's second
 * factor instead would lock the administrator out of the very feature they are using.
 *
 * @Flow\Scope("singleton")
 */
class CurrentAccount
{
    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $settings;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    public function get(): ?Account
    {
        if (!$this->securityContext->canBeInitialized()) {
            return null;
        }

        $providers = $this->settings->authenticationProviders();
        foreach ($this->securityContext->getAuthenticationTokens() as $token) {
            if (!$token->isAuthenticated() || !\in_array($token->getAuthenticationProviderName(), $providers, true)) {
                continue;
            }
            $account = $token->getAccount();
            if (!$account instanceof Account) {
                continue;
            }

            return $this->impersonator() ?? $account;
        }

        return null;
    }

    /** Whether the backend is being used on somebody else's behalf right now. */
    public function isImpersonating(): bool
    {
        return null !== $this->impersonator();
    }

    private function impersonator(): ?Account
    {
        if (!class_exists(ImpersonateService::class) || !$this->objectManager->isRegistered(ImpersonateService::class)) {
            return null;
        }

        try {
            /** @var ImpersonateService $impersonation */
            $impersonation = $this->objectManager->get(ImpersonateService::class);
            if (!$impersonation->isActive()) {
                return null;
            }
            $original = $impersonation->getOriginalIdentity();

            return $original instanceof Account ? $original : null;
        } catch (\Throwable) {
            // no session, or impersonation data that no longer resolves: nobody is impersonating
            return null;
        }
    }
}
