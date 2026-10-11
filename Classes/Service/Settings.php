<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Calmfox\TwoFactor\Core\Policy\Policy;
use Calmfox\TwoFactor\Domain\Repository\PolicySettingRepository;
use Calmfox\TwoFactor\Domain\Model\PolicySetting;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * The package's settings, read in one place and with the defaults decided in one place.
 *
 * @Flow\Scope("singleton")
 */
class Settings
{
    /**
     * @Flow\InjectConfiguration(package="Calmfox.TwoFactor")
     * @var array<string, mixed>
     */
    protected $settings = [];

    /**
     * @Flow\Inject
     * @var PolicySettingRepository
     */
    protected $policySettingRepository;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * The policy chosen in the backend, or the one from Settings.yaml until somebody chooses.
     *
     * A value that is not a policy, in either place, falls back to "required": a typo in the
     * configuration must never quietly turn the second factor off.
     */
    public function policy(): Policy
    {
        $stored = $this->policySettingRepository->current()?->getPolicy();
        $name = $stored ?? (string) ($this->settings['defaultPolicy'] ?? Policy::REQUIRED);

        return \in_array($name, Policy::ALL, true) ? new Policy($name) : new Policy(Policy::REQUIRED);
    }

    public function changePolicy(Policy $policy): void
    {
        $setting = $this->policySettingRepository->current();
        if (null === $setting) {
            $this->policySettingRepository->add(new PolicySetting($policy->name()));
        } else {
            $setting->change($policy->name());
            $this->policySettingRepository->update($setting);
        }
        $this->persistenceManager->persistAll();
    }

    /** @return list<string> */
    public function authenticationProviders(): array
    {
        $providers = $this->settings['authenticationProviders'] ?? [];

        return array_values(array_filter(\is_array($providers) ? $providers : [], static fn ($name): bool => \is_string($name) && '' !== $name));
    }

    public function totpIssuer(string $host): string
    {
        $issuer = trim((string) ($this->settings['totp']['issuer'] ?? ''));

        return '' !== $issuer ? $issuer : $host;
    }

    /** Never negative, and never more than two minutes either way, whatever is configured. */
    public function totpWindow(): int
    {
        return max(0, min(4, (int) ($this->settings['totp']['window'] ?? 1)));
    }

    public function passkeysEnabled(): bool
    {
        return (bool) ($this->settings['passkeys']['enabled'] ?? true);
    }

    public function passkeyName(string $host): string
    {
        $name = trim((string) ($this->settings['passkeys']['name'] ?? ''));

        return '' !== $name ? $name : $host;
    }

    public function passkeyDomain(): ?string
    {
        $domain = strtolower(trim((string) ($this->settings['passkeys']['domain'] ?? '')));

        return '' !== $domain ? $domain : null;
    }

    public function maximumAttempts(): int
    {
        return max(1, (int) ($this->settings['maximumAttempts'] ?? 5));
    }
}
