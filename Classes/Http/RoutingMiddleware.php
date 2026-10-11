<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Http;

use Calmfox\TwoFactor\Core\Http\Paths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Http\ServerRequestAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends our few addresses to our controllers, whatever Flow's router made of them.
 *
 * Runs right after the router and overrides its answer only for the addresses in Paths. Why
 * this is not an entry in Routes.yaml is explained there: in a project that keeps its own
 * Configuration/Routes.yaml, a package route lands behind Neos' catch-all and is never reached,
 * and for this package that would mean a user who cannot get past the password.
 *
 * @Flow\Scope("singleton")
 */
class RoutingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $match = Paths::match($request->getMethod(), RequestInformationHelper::getRelativeRequestPath($request));
        if (null === $match) {
            return $next->handle($request);
        }

        return $next->handle($request->withAttribute(ServerRequestAttributes::ROUTING_RESULTS, [
            '@package' => 'Calmfox.TwoFactor',
            '@subpackage' => '',
            '@controller' => $match['controller'],
            '@action' => $match['action'],
            '@format' => $match['format'],
        ]));
    }
}
