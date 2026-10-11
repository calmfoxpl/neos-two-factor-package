<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Domain\Model;

use Calmfox\TwoFactor\Core\Policy\Policy;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * The policy as it was last chosen in the backend or on the command line.
 *
 * In the database rather than in Settings.yaml, because the person who decides who needs a
 * second factor is an administrator in the backend, not whoever deploys the configuration.
 * There is at most one row; without it the policy from Settings.yaml applies.
 *
 * @Flow\Entity
 * @ORM\Table(name="calmfox_twofactor_policy")
 */
class PolicySetting
{
    /**
     * @ORM\Column(length=16)
     * @var string
     */
    protected $policy;

    public function __construct(string $policy)
    {
        $this->change($policy);
    }

    public function getPolicy(): string
    {
        return $this->policy;
    }

    public function change(string $policy): void
    {
        // refuses anything the core does not know, before it reaches the database
        $this->policy = (new Policy($policy))->name();
    }
}
