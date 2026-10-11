<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Http;

/**
 * The addresses of the pages that stand between the password and the backend, and which
 * controller action answers each of them.
 *
 * These are matched here rather than declared in Routes.yaml on purpose. Flow appends routes
 * from package settings AFTER the routes a project keeps in its own Configuration/Routes.yaml,
 * and Neos' routes there catch every remaining address, so in such a project a route of ours
 * would never be reached. For most packages that is a page that does not load. For this one
 * it would be a user who typed the right password and is then redirected, in a circle, to a
 * page that does not exist. A table this small is cheaper than that risk: the middleware that
 * reads it runs right after Flow's router and simply overrides the router's answer for these
 * few addresses, whatever the project's routes say.
 *
 * Plain strings in, plain strings out, so the table can be tested without Flow.
 */
final class Paths
{
    /** Under Neos' own prefix, so that web server rules written for the backend cover it too. */
    public const PREFIX = 'neos/two-factor';

    public const CHALLENGE = 'verify';

    public const CHALLENGE_PASSKEY_OPTIONS = 'verify/passkey-options';

    public const CHALLENGE_PASSKEY = 'verify/passkey';

    public const SETUP = 'setup';

    public const SETUP_APP = 'setup/app';

    public const SETUP_PASSKEY_OPTIONS = 'setup/passkey-options';

    public const SETUP_PASSKEY = 'setup/passkey';

    public const SIGN_OUT = 'sign-out';

    public const ACCOUNT_PASSKEY_OPTIONS = 'account/passkey-options';

    public const ACCOUNT_PASSKEY = 'account/passkey';

    public const MANAGE_RESET = 'manage/reset';

    public const MANAGE_TURN_OFF = 'manage/turn-off';

    /**
     * Method and path to controller and action. Anything that changes state is POST only, so a
     * link in an e-mail or an <img> on another site cannot trigger it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ROUTES = [
        'GET ' . self::CHALLENGE => ['Challenge', 'index'],
        'POST ' . self::CHALLENGE => ['Challenge', 'verify'],
        'POST ' . self::CHALLENGE_PASSKEY_OPTIONS => ['Challenge', 'passkeyOptions'],
        'POST ' . self::CHALLENGE_PASSKEY => ['Challenge', 'passkeyVerify'],
        'GET ' . self::SETUP => ['Setup', 'index'],
        'POST ' . self::SETUP_APP => ['Setup', 'pairApp'],
        'POST ' . self::SETUP_PASSKEY_OPTIONS => ['Setup', 'passkeyOptions'],
        'POST ' . self::SETUP_PASSKEY => ['Setup', 'passkeyRegister'],
        'POST ' . self::SIGN_OUT => ['Challenge', 'signOut'],
        // adding a passkey later, from the user's own settings; the guard applies as usual
        'POST ' . self::ACCOUNT_PASSKEY_OPTIONS => ['AccountPasskey', 'options'],
        'POST ' . self::ACCOUNT_PASSKEY => ['AccountPasskey', 'register'],
        // an administrator's reset and turn off, from a user's page in Neos' user management
        'POST ' . self::MANAGE_RESET => ['Manage', 'reset'],
        'POST ' . self::MANAGE_TURN_OFF => ['Manage', 'turnOff'],
    ];

    /**
     * The controller and action for a request, or null when the address is not one of ours.
     *
     * @param string $relativePath the path below the site's base URI, with or without slashes
     *                             around it, as Flow's RequestInformationHelper returns it
     *
     * @return array{controller: string, action: string, format: string}|null
     */
    public static function match(string $method, string $relativePath): ?array
    {
        $method = strtoupper($method);
        // a HEAD request is a GET without the body, and some monitoring tools send nothing else
        if ('HEAD' === $method) {
            $method = 'GET';
        }

        $path = trim($relativePath, '/');
        $prefix = self::PREFIX . '/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $route = self::ROUTES[$method . ' ' . substr($path, \strlen($prefix))] ?? null;
        if (null === $route) {
            return null;
        }

        return [
            'controller' => $route[0],
            'action' => $route[1],
            // the passkey endpoints are called by the page's script and answer in JSON
            'format' => str_contains($path, 'passkey') ? 'json' : 'html',
        ];
    }

    /** The path of one of the pages, below the base URI and without a leading slash. */
    public static function path(string $page): string
    {
        foreach (array_keys(self::ROUTES) as $route) {
            if (substr($route, strpos($route, ' ') + 1) === $page) {
                return self::PREFIX . '/' . $page;
            }
        }

        throw new \InvalidArgumentException(sprintf('There is no two-factor page called "%s".', $page));
    }

    /**
     * The address of a page of this site, on the site's base URI rather than on the host and
     * port the request happened to arrive at. With a configured base URI, or behind a proxy,
     * the two differ; the address we keep and later compare must be on the same base as the
     * links Neos builds, or a page of this site would look like somebody else's.
     *
     * @param string $baseUri      the site's base URI, ending in a slash
     * @param string $relativePath the path below the base URI, as Flow's RequestInformationHelper returns it
     */
    public static function onSite(string $baseUri, string $relativePath, string $query = ''): string
    {
        return $baseUri . ltrim($relativePath, '/') . ('' !== $query ? '?' . $query : '');
    }

    /**
     * Whether a response to this request can be a page. A redirect is useful to somebody
     * looking at a browser tab; to the script of the content editor fetching JSON, a login page
     * in place of the data is only a confusing error. Those requests get a plain refusal
     * instead, and the next page the person loads takes them where they need to go.
     */
    public static function wantsPage(string $accept, string $requestedWith = ''): bool
    {
        if ('xmlhttprequest' === strtolower(trim($requestedWith))) {
            return false;
        }

        $accept = strtolower($accept);
        if ('' === trim($accept)) {
            return true;
        }

        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }
}
