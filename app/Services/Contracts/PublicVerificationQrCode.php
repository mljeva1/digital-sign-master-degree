<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class PublicVerificationQrCode
{
    public function dataUri(string $verificationUrl): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220, 2),
            new SvgImageBackEnd
        );
        $svg = (new Writer($renderer))->writeString($verificationUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
