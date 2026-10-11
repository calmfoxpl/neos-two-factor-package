<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Domain\Repository;

use Calmfox\TwoFactor\Domain\Model\PolicySetting;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 */
class PolicySettingRepository extends Repository
{
    public function current(): ?PolicySetting
    {
        $setting = $this->createQuery()->setLimit(1)->execute()->getFirst();

        return $setting instanceof PolicySetting ? $setting : null;
    }
}
