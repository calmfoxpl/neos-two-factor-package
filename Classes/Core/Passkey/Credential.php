<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Core\Passkey;

/**
 * One paired passkey, as it is kept on the account: an id, a name the user will
 * recognise, the device's public key, the signature counter and two dates.
 *
 * There is no private key here and there never will be — it stays on the device, which is the
 * whole reason a passkey cannot be phished, copied out of a database or read over the phone.
 */
final class Credential
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $publicKey,
        public readonly int $signCount = 0,
        public readonly ?string $createdAt = null,
        public readonly ?string $lastUsedAt = null,
    ) {
        if ('' === $id || '' === $publicKey) {
            throw new \InvalidArgumentException('A passkey needs an id and a public key.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            self::cleanName((string) ($data['name'] ?? '')),
            (string) ($data['publicKey'] ?? ''),
            (int) ($data['signCount'] ?? 0),
            isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            isset($data['lastUsedAt']) ? (string) $data['lastUsedAt'] : null,
        );
    }

    /** @return array{id: string, name: string, publicKey: string, signCount: int, createdAt: string|null, lastUsedAt: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'publicKey' => $this->publicKey,
            'signCount' => $this->signCount,
            'createdAt' => $this->createdAt,
            'lastUsedAt' => $this->lastUsedAt,
        ];
    }

    public function used(int $signCount, string $when): self
    {
        return new self($this->id, $this->name, $this->publicKey, $signCount, $this->createdAt, $when);
    }

    /** A name typed by a person, kept short enough to show and never empty. */
    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return '' === $name ? 'Passkey' : mb_substr($name, 0, 64);
    }
}
