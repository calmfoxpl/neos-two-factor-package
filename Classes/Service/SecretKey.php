<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Calmfox\TwoFactor\Core\Crypto\SecretBox;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Cryptography\HashService;

/**
 * Seals and opens authenticator-app secrets with a key that belongs to this installation.
 *
 * The key is derived from Flow's own encryption key (Data/Persistent/EncryptionKey, or the
 * FLOW_ENCRYPTION_KEY the project sets), reached through an HMAC because Flow does not hand
 * the key itself out. Nothing new to configure and nothing new to back up; but a new Flow
 * encryption key makes the stored secrets unreadable, and those users pair their app again.
 *
 * @Flow\Scope("singleton")
 */
class SecretKey
{
    private const PURPOSE = 'Calmfox.TwoFactor: authenticator app secrets';

    /**
     * @Flow\Inject
     * @var HashService
     */
    protected $hashService;

    private ?string $key = null;

    public function seal(#[\SensitiveParameter] string $secret): string
    {
        return SecretBox::seal($secret, $this->key());
    }

    public function open(string $sealed): ?string
    {
        return SecretBox::open($sealed, $this->key());
    }

    private function key(): string
    {
        return $this->key ??= SecretBox::deriveKey($this->hashService->generateHmac(self::PURPOSE), self::PURPOSE);
    }
}
