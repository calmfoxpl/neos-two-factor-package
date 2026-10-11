<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Passkey;

/**
 * The passkeys on one account. Keeping the bookkeeping here — which ids are known, which one
 * signed a response, what the counter was last time — means the WebAuthn ceremony only has to
 * deal with cryptography, and this can be tested without a browser.
 */
final class CredentialSet
{
    /** @var list<Credential> */
    private array $credentials;

    /** @param list<Credential> $credentials */
    public function __construct(array $credentials = [])
    {
        $this->credentials = array_values($credentials);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function fromArray(array $rows): self
    {
        $credentials = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                try {
                    $credentials[] = Credential::fromArray($row);
                } catch (\InvalidArgumentException) {
                    // a half-written row is not a key anybody can sign in with; drop it
                }
            }
        }

        return new self($credentials);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Credential $credential): array => $credential->toArray(), $this->credentials);
    }

    /** @return list<Credential> */
    public function all(): array
    {
        return $this->credentials;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (Credential $credential): string => $credential->id, $this->credentials);
    }

    public function isEmpty(): bool
    {
        return [] === $this->credentials;
    }

    public function count(): int
    {
        return \count($this->credentials);
    }

    public function has(string $id): bool
    {
        return null !== $this->find($id);
    }

    /** The key with this id, compared in constant time so the table cannot be probed by timing. */
    public function find(string $id): ?Credential
    {
        if ('' === $id) {
            return null;
        }

        $found = null;
        foreach ($this->credentials as $credential) {
            if (hash_equals($credential->id, $id)) {
                $found = $credential;
            }
        }

        return $found;
    }

    /** @throws \RuntimeException when the device is already paired with this account */
    public function add(Credential $credential): self
    {
        if ($this->has($credential->id)) {
            throw new \RuntimeException('This passkey is already paired with the account.');
        }

        return new self([...$this->credentials, $credential]);
    }

    public function replace(Credential $credential): self
    {
        $replaced = array_map(
            static fn (Credential $existing): Credential => hash_equals($existing->id, $credential->id) ? $credential : $existing,
            $this->credentials,
        );

        return new self(array_values($replaced));
    }

    public function remove(string $id): self
    {
        return new self(array_values(array_filter(
            $this->credentials,
            static fn (Credential $credential): bool => !hash_equals($credential->id, $id),
        )));
    }

    /**
     * A counter that goes backwards means the response was replayed, or the key was cloned.
     * A device that reports 0 throughout does not count them at all, which is allowed.
     */
    public static function counterWentBackwards(int $stored, int $reported): bool
    {
        if (0 === $stored && 0 === $reported) {
            return false;
        }

        return $reported <= $stored && 0 !== $stored;
    }
}
