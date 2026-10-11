<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Controller;

use Calmfox\TwoFactor\Core\Http\Paths;
use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Domain\Repository\TwoFactorRepository;
use Calmfox\TwoFactor\Passkey\Ceremony;
use Calmfox\TwoFactor\Service\Assets;
use Calmfox\TwoFactor\Service\CurrentAccount;
use Calmfox\TwoFactor\Service\Guard;
use Calmfox\TwoFactor\Service\Settings;
use Calmfox\TwoFactor\Service\TwoFactorSession;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Neos\Controller\BackendUserTranslationTrait;
use Neos\Neos\Domain\Service\UserService;

/**
 * What the pages between the password and the backend have in common: who is signing in,
 * where they go next, and how the page speaks to them.
 */
abstract class AbstractTwoFactorController extends ActionController
{
    // the pages speak the language the user chose for the backend, as Neos' own do
    use BackendUserTranslationTrait;

    /** The pages answer browsers; the passkey endpoints answer the pages' script. */
    protected $supportedMediaTypes = ['text/html', 'application/json'];

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
     * @var TwoFactorSession
     */
    protected $twoFactorSession;

    /**
     * @Flow\Inject
     * @var TwoFactorRepository
     */
    protected $repository;

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
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var AuthenticationManagerInterface
     */
    protected $authenticationManager;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var Assets
     */
    protected $assets;

    /**
     * @Flow\InjectConfiguration(package="Neos.Neos", path="userInterface.backendLoginForm")
     * @var array<string, mixed>
     */
    protected $loginForm = [];

    /** The account signing in; a request without one goes back to Neos' sign-in page. */
    protected function account(): Account
    {
        $account = $this->currentAccount->get();
        if (null === $account) {
            $this->redirect('index', 'Login', 'Neos.Neos');
        }

        return $account;
    }

    /**
     * Anybody not at the step this page is for goes to where they are actually needed: the
     * setup, the challenge, or on into the backend.
     */
    protected function continueUnless(Account $account, string $expected): void
    {
        $next = $this->guard->next($account);
        if ($next === $expected) {
            return;
        }
        if (Policy::SET_UP === $next) {
            $this->goTo(Paths::SETUP);
        }
        if (Policy::ANSWER === $next) {
            $this->goTo(Paths::CHALLENGE);
        }
        $this->redirectToUri($this->onwardUri());
    }

    /** One of our own pages. */
    protected function goTo(string $page): never
    {
        $this->redirectToUri($this->pageUri($page));
    }

    protected function pageUri(string $page): string
    {
        return $this->baseUri() . Paths::path($page);
    }

    /**
     * Where to go once nothing more is required: the page the guard interrupted, if it was on
     * this site, otherwise the backend's start page.
     */
    protected function onwardUri(): string
    {
        $destination = $this->twoFactorSession->takeDestination();
        if (null !== $destination && str_starts_with($destination, $this->baseUri()) && !str_contains($destination, '/' . Paths::PREFIX . '/')) {
            return $destination;
        }

        return $this->uriBuilder->reset()->setCreateAbsoluteUri(true)->uriFor('index', [], 'Backend\Backend', 'Neos.Neos');
    }

    /**
     * The same base Neos' own links are built on: the configured `Neos.Flow.http.baseUri`, or
     * the request as Flow's trusted-proxies handling rewrote it, so a site behind a proxy or
     * with a configured base URI is not sent to another host, port or scheme.
     */
    protected function baseUri(): string
    {
        return rtrim((string) $this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest($this->request->getHttpRequest()), '/') . '/';
    }

    protected function host(): string
    {
        return strtolower($this->request->getHttpRequest()->getUri()->getHost());
    }

    /** What the person is called in Neos, for the passkey dialog and the page greeting. */
    protected function displayName(Account $account): string
    {
        $user = $this->userService->getUser($account->getAccountIdentifier(), $account->getAuthenticationProviderName());
        $label = null !== $user ? trim((string) $user->getLabel()) : '';

        return '' !== $label ? $label : $account->getAccountIdentifier();
    }

    /** @param array<int|string, mixed> $arguments */
    protected function translate(string $id, array $arguments = []): string
    {
        return (string) $this->translator->translateById($id, $arguments, null, null, 'Main', 'Calmfox.TwoFactor');
    }

    protected function flash(string $id, string $severity = Message::SEVERITY_OK): void
    {
        $this->addFlashMessage($this->translate($id), '', $severity);
    }

    /**
     * Neos' own sign-in look: its stylesheets, its logo and its background, including whatever
     * a project changed there, so the step after the password looks like part of the same door.
     *
     * @return array<string, mixed>
     */
    protected function pageLook(): array
    {
        $stylesheets = array_values(array_filter((array) ($this->loginForm['stylesheets'] ?? []), static fn ($path): bool => \is_string($path) && '' !== $path));

        $background = $this->resourceUri($this->loginForm['backgroundImage'] ?? null);

        return [
            'stylesheets' => $stylesheets,
            'pageStylesheetUri' => $this->assets->uri('Styles/Page.css'),
            'passkeyScriptUri' => $this->assets->uri('Scripts/Passkey.js'),
            'logoUri' => $this->resourceUri($this->loginForm['logoImage'] ?? null),
            'backgroundUri' => $background,
            // a custom property rather than a <style> block: CSS braces and Fluid's do not mix
            'bodyStyle' => '' !== $background ? '--cf2fa-background: url("' . $background . '")' : '',
        ];
    }

    private function resourceUri(mixed $path): string
    {
        if (!\is_string($path) || '' === $path) {
            return '';
        }

        try {
            return (string) $this->resourceManager->getPublicPackageResourceUriByPath($path);
        } catch (\Throwable) {
            // a project that points the setting at something that is not there still gets a page
            return '';
        }
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): string
    {
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json');
        $this->response->setHttpHeader('Cache-Control', 'no-store');

        return (string) json_encode($data, \JSON_UNESCAPED_SLASHES);
    }

    /**
     * The JSON body the passkey script posted. Read directly rather than mapped to action
     * arguments: the browser's response is a nested structure of base64 strings, and nothing
     * about it should pass through a type converter.
     *
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $body = $this->request->getHttpRequest()->getParsedBody();
        if (!\is_array($body)) {
            $body = json_decode((string) $this->request->getHttpRequest()->getBody(), true);
        }

        return \is_array($body) ? $body : [];
    }
}
