<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\ViewHelpers;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;
use Neos\Neos\Domain\Model\User;
use Neos\Utility\PositionalArraySorter;

/**
 * Renders every section the installed Calmfox packages add to a user's page in Neos' user
 * management, in the order their positions say:
 *
 *     <cf2fa:userSections user="{user}" />
 *
 * Neos uses only one replacement of a page frame per action, so two packages that each replace
 * it for the user's page would hide one another. Instead each package registers its section in
 * Calmfox.UserManagement.sections, and each ships the same frame, which renders all of them:
 * whichever frame Neos picks, every section appears. A section is a class with
 *
 *     public function render(User $user, ActionRequest $request): string
 *
 * returning HTML, or '' when it has nothing to show (for instance to somebody without the
 * permission). The same contract is read by calmfox/neos-admin-invitation-package.
 */
class UserSectionsViewHelper extends AbstractViewHelper
{
    /** @var bool */
    protected $escapeOutput = false;

    /**
     * @Flow\InjectConfiguration(package="Calmfox.UserManagement", path="sections")
     * @var array<string, mixed>|null
     */
    protected $sections;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    public function initializeArguments(): void
    {
        $this->registerArgument('user', User::class, 'The Neos user whose page this is', true);
    }

    public function render(): string
    {
        $sections = array_filter((array) $this->sections, static fn ($section): bool => \is_array($section) && \is_string($section['renderer'] ?? null));
        $request = $this->renderingContext->getControllerContext()->getRequest();

        $output = '';
        foreach ((new PositionalArraySorter($sections))->toArray() as $section) {
            $class = $section['renderer'];
            if (!$this->objectManager->isRegistered($class)) {
                continue;
            }
            $renderer = $this->objectManager->get($class);
            if (method_exists($renderer, 'render')) {
                $output .= (string) $renderer->render($this->arguments['user'], $request);
            }
        }

        return $output;
    }
}
