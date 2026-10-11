<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Service;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Neos\Flow\Annotations as Flow;

/**
 * The QR code an authenticator app scans, as an SVG data URI.
 *
 * SVG rather than PNG so it needs no image extension and stays sharp on any screen, and a data
 * URI rather than a file so the secret never becomes a URL that could be opened, cached by a
 * proxy or found in a log.
 *
 * @Flow\Scope("singleton")
 */
class QrCode
{
    public function dataUri(string $content, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($content));
    }
}
