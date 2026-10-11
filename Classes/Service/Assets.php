<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\ResourceManager;

/**
 * Addresses of the package's own stylesheets and script, with a fingerprint of their content.
 *
 * Flow publishes static resources under an address that never changes, and browsers keep them.
 * After an update the page would arrive with new markup and yesterday's stylesheet: a sign-in
 * step that looks broken exactly when people are least patient. The fingerprint changes with
 * the file, so the browser fetches the new one and keeps it for as long as it likes after that.
 *
 * @Flow\Scope("singleton")
 */
class Assets
{
    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /** @param string $path below Resources/Public, e.g. "Styles/Page.css" */
    public function uri(string $path): string
    {
        $resource = 'resource://Calmfox.TwoFactor/Public/' . $path;
        $uri = (string) $this->resourceManager->getPublicPackageResourceUriByPath($resource);
        $hash = is_file($resource) ? md5_file($resource) : false;

        return false !== $hash ? $uri . '?v=' . substr($hash, 0, 10) : $uri;
    }
}
