<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\UserManagement;

use Calmfox\TwoFactor\Core\Http\Paths;
use Calmfox\TwoFactor\Domain\Repository\TwoFactorRepository;
use Calmfox\TwoFactor\Service\AccountStatus;
use Calmfox\TwoFactor\Service\Assets;
use Calmfox\TwoFactor\Service\Settings;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\FluidAdaptor\View\StandaloneView;
use Neos\Neos\Domain\Model\User;

/**
 * The second factor on a user's page in Neos' user management (details and edit), with reset
 * and turn off. Registered in Calmfox.UserManagement.sections (see UserSectionsViewHelper).
 *
 * Nothing is rendered for somebody who may not manage second factors, so the section appears
 * for administrators only, whoever else can open the user management.
 *
 * @Flow\Scope("singleton")
 */
class TwoFactorSection
{
    /**
     * @Flow\Inject
     * @var PrivilegeManagerInterface
     */
    protected $privilegeManager;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var TwoFactorRepository
     */
    protected $repository;

    /**
     * @Flow\Inject
     * @var AccountStatus
     */
    protected $accountStatus;

    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $settings;

    /**
     * @Flow\Inject
     * @var BaseUriProvider
     */
    protected $baseUriProvider;

    /**
     * @Flow\Inject
     * @var Assets
     */
    protected $assets;

    public function render(User $user, ActionRequest $request): string
    {
        if (!$this->privilegeManager->isPrivilegeTargetGranted('Calmfox.TwoFactor:Module.Users')) {
            return '';
        }

        $accounts = [];
        foreach ($user->getAccounts() as $account) {
            if ($account instanceof Account && $this->accountStatus->applies($account)) {
                $accounts[] = $this->accountStatus->of($account, $this->repository->findOneByAccount($account));
            }
        }
        if ([] === $accounts) {
            return '';
        }

        $httpRequest = $request->getHttpRequest();
        // the base Neos' own links are built on (configured, or the request as Flow's trusted
        // proxies rewrote it); returnTo is on the same base, or ManageController::back() would
        // not recognise the page the form came from
        $base = rtrim((string) $this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest($httpRequest), '/') . '/';

        $view = new StandaloneView($request);
        $view->setTemplatePathAndFilename('resource://Calmfox.TwoFactor/Private/Partials/CalmfoxTwoFactor/UserSection.html');
        $view->setPartialRootPaths(['resource://Calmfox.TwoFactor/Private/Partials']);
        $view->assign('twoFactor', [
            'accounts' => $accounts,
            'active' => $this->settings->policy()->isActive(),
            'resetUri' => $base . Paths::path(Paths::MANAGE_RESET),
            'turnOffUri' => $base . Paths::path(Paths::MANAGE_TURN_OFF),
            'returnTo' => Paths::onSite($base, RequestInformationHelper::getRelativeRequestPath($httpRequest), $httpRequest->getUri()->getQuery()),
            'csrfToken' => $this->securityContext->getCsrfProtectionToken(),
            'stylesheetUri' => $this->assets->uri('Styles/Module.css'),
            // for the confirmation before reset and turn off
            'scriptUri' => $this->assets->uri('Scripts/Passkey.js'),
        ]);

        return (string) $view->render();
    }
}
