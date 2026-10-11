<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Passkey;

use Calmfox\TwoFactor\Core\Passkey\Credential;
use Calmfox\TwoFactor\Core\Passkey\CredentialSet;
use Calmfox\TwoFactor\Domain\Model\TwoFactor;
use Calmfox\TwoFactor\Service\Settings;
use Calmfox\TwoFactor\Service\TwoFactorSession;
use lbuchs\WebAuthn\WebAuthn;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two WebAuthn ceremonies: pairing a passkey with an account, and answering a sign-in
 * challenge with one.
 *
 * A passkey is the one second factor phishing cannot relay: the signature covers the address
 * of the site, so a look-alike page never gets a valid one. Six digits can be retyped into a
 * fake form; a passkey cannot.
 *
 * Built on lbuchs/webauthn, which has no dependencies of its own: a package that guards the
 * backend should not drag a dependency tree into a site. Challenges live in the session and
 * are used once: a failed attempt burns the challenge too.
 *
 * @Flow\Scope("singleton")
 */
class Ceremony
{
    /** Seconds the browser waits for the gesture; Face ID is sometimes preceded by finding the phone. */
    private const TIMEOUT = 120;

    /**
     * @Flow\Inject
     * @var Settings
     */
    protected $settings;

    /**
     * @Flow\Inject
     * @var TwoFactorSession
     */
    protected $session;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /** Whether this request can use passkeys at all: switched on, and over https or on localhost. */
    public function isAvailable(ServerRequestInterface $request): bool
    {
        return $this->settings->passkeysEnabled() && $this->isSecureEnough($request);
    }

    /**
     * Options for navigator.credentials.create(): pairing a new passkey.
     *
     * @return array<string, mixed>
     *
     * @throws PasskeyException
     */
    public function registrationOptions(Account $account, string $displayName, TwoFactor $record, ServerRequestInterface $request): array
    {
        $this->assertAvailable($request);

        $webAuthn = $this->webAuthn($request);
        $username = $account->getAccountIdentifier();

        $arguments = self::publicKey($webAuthn->getCreateArgs(
            $this->userHandle($account),
            $username,
            '' !== trim($displayName) ? trim($displayName) : $username,
            self::TIMEOUT,
            'preferred',
            true,
            null,
            // the same device must not pair a second key with the same account
            self::binaryIds($record->getPasskeys()),
        ));
        // no attestation: which device model somebody carries is none of our business
        $arguments->attestation = 'none';

        $this->session->rememberPasskeyChallenge($webAuthn->getChallenge()->getBinaryString());

        return self::toArray($arguments);
    }

    /**
     * Verifies what the device signed when pairing and returns the account's keys with the new
     * one added. The caller saves.
     *
     * @param array<string, mixed> $response
     *
     * @throws PasskeyException
     */
    public function register(TwoFactor $record, ServerRequestInterface $request, array $response, string $name): CredentialSet
    {
        $this->assertAvailable($request);

        $challenge = $this->takeChallenge();
        $clientDataJson = self::field($response, 'clientDataJSON');
        $this->assertOrigin($request, $clientDataJson);

        try {
            $data = $this->webAuthn($request)->processCreate(
                $clientDataJson,
                self::field($response, 'attestationObject'),
                $challenge,
                true,  // user verification: a fingerprint, a face or the device PIN
                true,
                false, // no attestation roots, because we asked for no attestation
            );
        } catch (\Throwable $exception) {
            throw PasskeyException::rejected($exception);
        }

        if (!\is_string($data->credentialId ?? null) || !\is_string($data->credentialPublicKey ?? null)) {
            throw PasskeyException::rejected();
        }

        $credential = new Credential(
            self::encode($data->credentialId),
            Credential::cleanName($name),
            $data->credentialPublicKey,
            \is_int($data->signatureCounter ?? null) ? $data->signatureCounter : 0,
            self::now(),
        );

        try {
            return $record->getPasskeys()->add($credential);
        } catch (\RuntimeException) {
            throw PasskeyException::alreadyPaired();
        }
    }

    /**
     * Options for navigator.credentials.get(), limited to this account's own keys.
     *
     * @return array<string, mixed>
     *
     * @throws PasskeyException
     */
    public function loginOptions(TwoFactor $record, ServerRequestInterface $request): array
    {
        $this->assertAvailable($request);

        if ($record->getPasskeys()->isEmpty()) {
            throw PasskeyException::rejected();
        }

        $webAuthn = $this->webAuthn($request);
        $arguments = self::publicKey($webAuthn->getGetArgs(
            self::binaryIds($record->getPasskeys()),
            self::TIMEOUT,
            true,
            true,
            true,
            true,
            true,
            true, // user verification required
        ));

        $this->session->rememberPasskeyChallenge($webAuthn->getChallenge()->getBinaryString());

        return self::toArray($arguments);
    }

    /**
     * Verifies a sign-in response against this account's keys and returns the keys with the
     * used one's counter and date brought up to date. The caller saves.
     *
     * @param array<string, mixed> $response
     *
     * @throws PasskeyException
     */
    public function verify(Account $account, TwoFactor $record, ServerRequestInterface $request, array $response): CredentialSet
    {
        $this->assertAvailable($request);

        $challenge = $this->takeChallenge();
        $passkeys = $record->getPasskeys();

        // a key belonging to another account never completes this one's sign-in
        $credential = $passkeys->find(\is_string($response['rawId'] ?? null) ? $response['rawId'] : '');
        if (null === $credential) {
            throw PasskeyException::rejected();
        }

        $userHandle = self::responseField($response, 'userHandle');
        if (\is_string($userHandle) && '' !== $userHandle && !hash_equals($this->userHandle($account), self::decode($userHandle))) {
            throw PasskeyException::rejected();
        }

        $clientDataJson = self::field($response, 'clientDataJSON');
        $this->assertOrigin($request, $clientDataJson);

        $webAuthn = $this->webAuthn($request);

        try {
            $webAuthn->processGet(
                $clientDataJson,
                self::field($response, 'authenticatorData'),
                self::field($response, 'signature'),
                $credential->publicKey,
                $challenge,
                $credential->signCount,
                true,
                true,
            );
        } catch (\Throwable $exception) {
            throw PasskeyException::rejected($exception);
        }

        $counter = $webAuthn->getSignatureCounter();

        return $passkeys->replace($credential->used(\is_int($counter) ? $counter : $credential->signCount, self::now()));
    }

    /** @throws PasskeyException */
    private function assertAvailable(ServerRequestInterface $request): void
    {
        if (!$this->settings->passkeysEnabled()) {
            throw PasskeyException::notAvailable();
        }
        if (!$this->isSecureEnough($request)) {
            throw PasskeyException::insecureOrigin();
        }
    }

    /**
     * Browsers allow WebAuthn over https, and over plain http on localhost only. Flow has
     * already applied any trusted proxy headers to the URI, so the scheme here is the one the
     * browser used.
     */
    private function isSecureEnough(ServerRequestInterface $request): bool
    {
        if ('https' === strtolower($request->getUri()->getScheme())) {
            return true;
        }

        return \in_array(self::host($request), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** @throws PasskeyException */
    private function takeChallenge(): string
    {
        $challenge = $this->session->takePasskeyChallenge();
        if (null === $challenge) {
            throw PasskeyException::challengeGone();
        }

        return $challenge;
    }

    private function webAuthn(ServerRequestInterface $request): WebAuthn
    {
        return new WebAuthn($this->settings->passkeyName(self::host($request)), $this->domain($request), null, true);
    }

    private function domain(ServerRequestInterface $request): string
    {
        return $this->settings->passkeyDomain() ?? self::host($request);
    }

    private static function host(ServerRequestInterface $request): string
    {
        return strtolower($request->getUri()->getHost());
    }

    /**
     * The library accepts any host merely ending with the domain; we want the exact site or a
     * real subdomain of it, over https (plain http only on localhost, as browsers allow).
     *
     * @throws PasskeyException
     */
    private function assertOrigin(ServerRequestInterface $request, string $clientDataJson): void
    {
        $clientData = json_decode($clientDataJson, true);
        $origin = \is_array($clientData) && \is_string($clientData['origin'] ?? null) ? $clientData['origin'] : '';
        $host = strtolower((string) parse_url($origin, \PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($origin, \PHP_URL_SCHEME));
        $domain = strtolower($this->domain($request));

        $hostMatches = $host === $domain || str_ends_with($host, '.' . $domain);
        $schemeAllowed = 'https' === $scheme || ('http' === $scheme && \in_array($host, ['localhost', '127.0.0.1'], true));

        if (!$hostMatches || !$schemeAllowed) {
            throw PasskeyException::rejected();
        }
    }

    /**
     * The WebAuthn user handle: the account's persistence identifier, a random UUID. Never the
     * user name or an e-mail, because the handle must not identify a person.
     */
    private function userHandle(Account $account): string
    {
        return (string) $this->persistenceManager->getIdentifierByObject($account);
    }

    /** @return list<string> */
    private static function binaryIds(CredentialSet $passkeys): array
    {
        return array_map(static fn (string $id): string => self::decode($id), $passkeys->ids());
    }

    /** @throws PasskeyException */
    private static function publicKey(mixed $arguments): \stdClass
    {
        if (!$arguments instanceof \stdClass || !($arguments->publicKey ?? null) instanceof \stdClass) {
            throw PasskeyException::rejected();
        }

        return $arguments->publicKey;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @throws PasskeyException
     */
    private static function field(array $response, string $name): string
    {
        $value = self::responseField($response, $name);
        if (null === $value || '' === $value) {
            throw PasskeyException::rejected();
        }

        return self::decode($value);
    }

    /** @param array<string, mixed> $response */
    private static function responseField(array $response, string $name): ?string
    {
        $fields = $response['response'] ?? null;
        $value = \is_array($fields) ? ($fields[$name] ?? null) : null;

        return \is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function toArray(\stdClass $value): array
    {
        /** @var array<string, mixed> $array */
        $array = json_decode(json_encode($value, \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR);

        return $array;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
