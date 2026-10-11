<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Http;

use Calmfox\TwoFactor\Core\Http\Paths;
use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Service\CurrentAccount;
use Calmfox\TwoFactor\Service\Guard;
use Calmfox\TwoFactor\Service\TwoFactorSession;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Http\ServerRequestAttributes;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The guard. After the password, and before anything else in the backend, a user is sent to
 * pair a second factor or to answer one.
 *
 * It sits in the middleware chain rather than on the sign-in itself, because the question is
 * not "did you just sign in" but "may this request go on": a session that never answered its
 * challenge is stopped on every request, including one opened from a bookmark hours later, and
 * including the frontend, where a backend session would otherwise show unpublished content.
 *
 * @Flow\Scope("singleton")
 */
class GuardMiddleware implements MiddlewareInterface
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
     * @var TwoFactorSession
     */
    protected $session;

    /**
     * @Flow\Inject
     * @var BaseUriProvider
     */
    protected $baseUriProvider;

    /**
     * @Flow\Inject
     * @var ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * @Flow\Inject
     * @var StreamFactoryInterface
     */
    protected $streamFactory;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        if (self::isAlwaysAllowed($request->getAttribute(ServerRequestAttributes::ROUTING_RESULTS))) {
            return $next->handle($request);
        }

        $account = $this->currentAccount->get();
        if (null === $account) {
            return $next->handle($request);
        }

        $required = $this->guard->next($account);
        if (Policy::NOTHING === $required) {
            return $next->handle($request);
        }

        $page = Policy::SET_UP === $required ? Paths::SETUP : Paths::CHALLENGE;

        if (!Paths::wantsPage($request->getHeaderLine('Accept'), $request->getHeaderLine('X-Requested-With'))) {
            // a script cannot show a page; it gets a refusal, and the next page load redirects
            return $this->responseFactory->createResponse(403)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store')
                ->withBody($this->streamFactory->createStream((string) json_encode([
                    'error' => 'Two-step verification is required before this request can be answered.',
                    'location' => $this->uri($request, $page),
                ])));
        }

        if ('GET' === $request->getMethod()) {
            // kept on the same base as onwardUri() compares it against, not on the host and
            // port this request arrived at
            $this->session->rememberDestination(Paths::onSite($this->baseUri($request), RequestInformationHelper::getRelativeRequestPath($request), $request->getUri()->getQuery()));
        }

        return $this->responseFactory->createResponse(303)
            ->withHeader('Location', $this->uri($request, $page))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Our own pages, and Neos' sign-in and sign-out, must stay reachable while the answer is
     * pending: the first to give it, the second so that somebody who cannot give it is never
     * trapped.
     */
    private static function isAlwaysAllowed(mixed $routing): bool
    {
        if (!\is_array($routing)) {
            return false;
        }
        $package = strtolower((string) ($routing['@package'] ?? ''));
        $controller = strtolower((string) ($routing['@controller'] ?? ''));

        return ('calmfox.twofactor' === $package && \in_array($controller, ['challenge', 'setup'], true))
            || ('neos.neos' === $package && 'login' === $controller);
    }

    private function uri(ServerRequestInterface $request, string $page): string
    {
        return $this->baseUri($request) . Paths::path($page);
    }

    /**
     * The same base Neos' own links are built on: the configured `Neos.Flow.http.baseUri`, or
     * the request as Flow's trusted-proxies handling rewrote it. The bare request would send a
     * site behind a proxy, or with a configured base URI, to another host, port or scheme.
     */
    private function baseUri(ServerRequestInterface $request): string
    {
        return rtrim((string) $this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest($request), '/') . '/';
    }
}
